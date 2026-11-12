<?php

namespace Tests\Feature;

use App\Models\LabTest as CatalogueTest;
use App\Models\Patient;
use App\Models\User;
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
}
