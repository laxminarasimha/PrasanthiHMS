<?php

namespace Tests\Feature;

use App\Models\LabTest as CatalogueTest;
use App\Models\Patient;
use App\Models\User;
use App\Support\LabDepartments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LabTest extends TestCase
{
    use RefreshDatabase;

    private array $lipid = [
        'name' => 'Lipid Profile', 'department' => 'Biochemistry', 'sample_type' => 'Blood', 'price' => 500,
        'parameters' => [
            ['name' => 'Total Cholesterol', 'unit' => 'mg/dL', 'reference_range' => '0 - 200'],
            ['name' => 'HDL Cholesterol', 'unit' => 'mg/dL', 'reference_range' => '40 - 60'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
    }

    private function catalogue(array $overrides = []): array
    {
        return $this->postJson('/api/lab/tests', $overrides + $this->lipid)->assertCreated()->json();
    }

    private function order(array $testIds): array
    {
        return $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => $testIds])
            ->assertCreated()->json();
    }

    public function test_catalogue_test_is_saved_with_ordered_parameters_and_can_be_edited(): void
    {
        $test = $this->catalogue();
        $this->assertSame(['Total Cholesterol', 'HDL Cholesterol'], array_column($test['parameters'], 'name'));

        $this->putJson("/api/lab/tests/{$test['id']}", ['price' => 550, 'parameters' => [['name' => 'Triglycerides']]] + $this->lipid)
            ->assertOk()->assertJsonPath('price', 550)->assertJsonCount(1, 'parameters');

        $this->postJson('/api/lab/tests', ['name' => 'Empty', 'price' => 10, 'parameters' => []])
            ->assertStatus(422)->assertJsonValidationErrors('parameters');
    }

    public function test_only_lab_roles_can_edit_the_catalogue_or_enter_results(): void
    {
        $order = $this->order([$this->catalogue()['id']]);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));

        $this->postJson('/api/lab/tests', $this->lipid)->assertForbidden();
        $this->putJson("/api/lab/orders/{$order['id']}/items/{$order['items'][0]['id']}", ['results' => []])->assertForbidden();
        // Front desk can still place an order.
        $this->postJson('/api/lab/orders', ['patient_id' => $order['patient']['id'], 'test_ids' => [CatalogueTest::first()->id]])->assertCreated();
    }

    public function test_ordering_creates_result_sheets_and_a_bill_for_the_tests(): void
    {
        $rbs = $this->catalogue(['name' => 'RBS', 'price' => 80, 'parameters' => [['name' => 'Random Blood Sugar', 'unit' => 'mg/dL']]]);
        $order = $this->order([$this->catalogue()['id'], $rbs['id']]);

        $this->assertSame('pending', $order['status']);
        $this->assertMatchesRegularExpression('/^L\d{5}$/', $order['order_no']);
        $this->assertCount(2, $order['items']);
        $this->assertEquals(580, $order['bill']['total']);
        $this->assertSame('Total Cholesterol', $order['items'][0]['results'][0]['parameter']);
        $this->assertNull($order['items'][0]['results'][0]['value']);

        $this->postJson('/api/lab/orders', ['patient_id' => $order['patient']['id'], 'test_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('test_ids');
    }

    public function test_results_move_the_order_from_pending_to_partial_to_completed(): void
    {
        $rbs = $this->catalogue(['name' => 'RBS', 'price' => 80, 'parameters' => [['name' => 'Random Blood Sugar']]]);
        $order = $this->order([$this->catalogue()['id'], $rbs['id']]);
        [$first, $second] = $order['items'];
        $url = fn (array $item) => "/api/lab/orders/{$order['id']}/items/{$item['id']}";

        // A draft keeps the order pending.
        $this->putJson($url($first), ['results' => [['id' => $first['results'][0]['id'], 'value' => '245', 'flag' => 'high']]])
            ->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonPath('items.0.results.0.value', '245')->assertJsonPath('items.0.results.0.flag', 'high');

        $this->putJson($url($first), ['complete' => true, 'results' => []])->assertOk()->assertJsonPath('status', 'partial');
        $this->putJson($url($second), ['complete' => true, 'remarks' => 'Sample slightly haemolysed', 'results' => [['id' => $second['results'][0]['id'], 'value' => '110']]])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('items.1.remarks', 'Sample slightly haemolysed');

        // Reopening one test for correction drops the order back.
        $this->putJson($url($second), ['complete' => false, 'results' => []])->assertOk()->assertJsonPath('status', 'partial');
    }

    public function test_orders_list_by_day_and_old_reports_are_found_by_patient(): void
    {
        $order = $this->order([$this->catalogue()['id']]);
        $this->order([CatalogueTest::first()->id]);

        $this->getJson('/api/lab/orders')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/lab/orders?date='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(0);
        $this->getJson('/api/lab/orders?q='.$order['patient']['uhid'])->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $order['id']);
        $this->getJson('/api/lab/orders?q='.$order['order_no'])->assertOk()->assertJsonCount(1);
    }

    public function test_whole_order_is_collected_and_approved_in_one_step(): void
    {
        $rbs = $this->catalogue(['name' => 'RBS', 'price' => 80, 'parameters' => [['name' => 'Random Blood Sugar']]]);
        $order = $this->order([$this->catalogue()['id'], $rbs['id']]);
        [$first, $second] = $order['items'];

        $this->putJson("/api/lab/orders/{$order['id']}/approve", ['all' => true])->assertStatus(422);

        $this->putJson("/api/lab/orders/{$order['id']}/collect", ['all' => true])->assertOk()
            ->assertJsonPath('items.0.stage', 'collected')->assertJsonPath('items.1.stage', 'collected');

        // Only what has been reported is approved; the other test stays where it is.
        $this->putJson("/api/lab/orders/{$order['id']}/items/{$first['id']}", ['complete' => true, 'results' => []])->assertOk();
        $this->putJson("/api/lab/orders/{$order['id']}/approve", ['all' => true])->assertOk()
            ->assertJsonPath('items.0.stage', 'approved')->assertJsonPath('items.1.stage', 'collected');

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson("/api/lab/orders/{$order['id']}/collect", ['all' => true])->assertForbidden();
        $this->putJson("/api/lab/orders/{$order['id']}/approve", ['all' => true])->assertForbidden();
    }

    public function test_a_test_name_suggests_its_department(): void
    {
        $guesses = [
            'Complete Blood Picture (CBP)' => 'Haematology', 'Haemoglobin' => 'Haematology', 'ESR' => 'Haematology', 'Blood Grouping and Rh Typing' => 'Haematology',
            'Lipid Profile' => 'Biochemistry', 'Fasting Blood Sugar' => 'Biochemistry', 'Serum Creatinine' => 'Biochemistry', 'Liver Function Test' => 'Biochemistry',
            // The word that says most wins: this is not haematology, and that is not clinical pathology.
            'Glycosylated Haemoglobin (HbA1c)' => 'Biochemistry', 'Urine Culture and Sensitivity' => 'Microbiology',
            'Complete Urine Examination' => 'Clinical Pathology', 'Urine Sugar' => 'Clinical Pathology', 'Stool for Occult Blood' => 'Clinical Pathology',
            'Widal Test' => 'Serology and Immunology', 'Dengue NS1 Antigen' => 'Serology and Immunology', 'HIV I and II' => 'Serology and Immunology', 'CRP' => 'Serology and Immunology',
            'Thyroid Profile (T3, T4, TSH)' => 'Hormones', 'Serum Prolactin' => 'Hormones',
            'FNAC' => 'Histopathology and Cytology', 'Chest X-Ray PA View' => 'Radiology and Imaging', 'USG Abdomen' => 'Radiology and Imaging', 'ECG' => 'Cardiology',
            // A name that says nothing is left for a person to file.
            'Special Panel 7' => null,
        ];
        foreach ($guesses as $name => $department) {
            $this->assertSame($department, LabDepartments::guess($name), $name);
        }
    }

    public function test_tests_are_sorted_into_departments_after_a_person_has_looked_them_over(): void
    {
        $cbp = CatalogueTest::create(['name' => 'Complete Blood Picture', 'price' => 300]);
        $odd = CatalogueTest::create(['name' => 'Special Panel 7', 'price' => 100]);
        $filed = $this->catalogue()['id']; // Lipid Profile, already under Biochemistry

        // Only the tests with no department are offered, each with what its name suggests.
        $offered = $this->getJson('/api/lab/tests/department-suggestions')->assertOk()->assertJsonCount(2)->json();
        $this->assertSame([$cbp->id => 'Haematology', $odd->id => null], array_column($offered, 'suggested', 'id'));
        $this->assertSame(null, $cbp->fresh()->department); // looking saves nothing

        // An order made before the test was filed.
        $order = $this->order([$cbp->id]);
        $this->assertNull($order['items'][0]['department']);

        $this->putJson('/api/lab/tests/departments', ['tests' => [['id' => $cbp->id, 'department' => 'Haematology'], ['id' => $odd->id, 'department' => 'Biochemistry']]])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame('Haematology', $cbp->fresh()->department);
        $this->getJson('/api/lab/tests/department-suggestions')->assertOk()->assertJsonCount(0);

        // That earlier order now shows under the department, and a new order carries it from the start.
        $this->getJson("/api/lab/orders/{$order['id']}")->assertOk()->assertJsonPath('items.0.department', 'Haematology');
        $this->assertSame('Haematology', $this->order([$cbp->id])['items'][0]['department']);

        // A department can be taken off again; other staff cannot do any of this.
        $this->putJson('/api/lab/tests/departments', ['tests' => [['id' => $filed, 'department' => null]]])->assertOk();
        $this->assertNull(CatalogueTest::find($filed)->department);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/lab/tests/department-suggestions')->assertForbidden();
        $this->putJson('/api/lab/tests/departments', ['tests' => [['id' => $filed, 'department' => 'Biochemistry']]])->assertForbidden();
    }

    public function test_the_lab_keeps_its_own_list_of_departments_and_print_options(): void
    {
        $settings = $this->getJson('/api/lab/settings')->assertOk()->assertJsonPath('group_by_department', true)->assertJsonPath('department_on_new_page', false)
            ->assertJsonPath('print_method', true)->assertJsonPath('print_end_mark', true)->assertJsonPath('report_note', '')->json();
        $this->assertSame(['Haematology', 'Biochemistry', 'Clinical Pathology'], array_slice($settings['departments'], 0, 3));

        $send = fn (array $changes) => $this->putJson('/api/lab/settings', $changes + array_intersect_key($settings, array_flip([
            'lab_name', 'lab_address', 'technician_1', 'technician_2', 'letterhead_top_mm', 'letterhead_bottom_mm', 'each_test_on_new_page',
            'allow_print_without_payment', 'print_sample_date', 'print_approved_date', 'approval_required',
        ])));
        $send(['departments' => ['Biochemistry', 'biochemistry']])->assertStatus(422)->assertJsonValidationErrors('departments.0');
        $send(['departments' => ['Biochemistry', ' Pathology ', 'Hematology'], 'department_on_new_page' => true, 'report_note' => 'Please correlate clinically.'])->assertOk()
            ->assertJsonPath('departments', ['Biochemistry', 'Pathology', 'Hematology'])->assertJsonPath('department_on_new_page', true)->assertJsonPath('report_note', 'Please correlate clinically.');
        // Saving the rest without these keeps them.
        $send([])->assertOk()->assertJsonPath('departments.1', 'Pathology')->assertJsonPath('report_note', 'Please correlate clinically.');
    }

    public function test_last_reported_value_is_shown_beside_a_new_result(): void
    {
        $test = $this->catalogue();
        $patient = Patient::factory()->create();
        $place = fn () => $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test['id']]])->assertCreated()->json();

        $old = $place();
        $this->assertNull($old['items'][0]['results'][0]['previous']);
        $this->putJson("/api/lab/orders/{$old['id']}/items/{$old['items'][0]['id']}", ['complete' => true, 'results' => [
            ['id' => $old['items'][0]['results'][0]['id'], 'value' => '232', 'flag' => 'high'],
        ]])->assertOk();

        // The next order of the same test for the same patient shows it; a value never entered shows nothing.
        $new = $place();
        $this->getJson("/api/lab/orders/{$new['id']}")->assertOk()
            ->assertJsonPath('items.0.results.0.previous.value', '232')
            ->assertJsonPath('items.0.results.0.previous.flag', 'high')
            ->assertJsonPath('items.0.results.0.previous.on', now()->toDateString())
            ->assertJsonPath('items.0.results.1.previous', null);

        // Another patient's result is never shown.
        $other = $this->order([$test['id']]);
        $this->assertNull($other['items'][0]['results'][0]['previous']);
    }
}
