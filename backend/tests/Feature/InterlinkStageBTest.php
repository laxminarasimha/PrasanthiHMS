<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\DoctorBlock;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stage B of interlinking: every name and number opens what it stands for. */
class InterlinkStageBTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctors_name_opens_their_card_with_what_each_person_may_see(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Card', 'phone' => '9000000001', 'department' => 'General Medicine',
            'availability' => [strtolower(now()->format('D')) => [['from' => '09:00', 'to' => '13:00']]]]);
        $waiting = Appointment::create(['patient_id' => Patient::factory()->create(['name' => 'Sample Waiting'])->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived', 'token_no' => 3]);
        Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'reviewed', 'token_no' => 1]);
        Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'cancelled']);
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '7', 'daily_charge' => 800]);
        $stay = Admission::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'bed_id' => $bed->id, 'admitted_at' => now()->subDay(), 'status' => 'admitted']);
        DoctorBlock::create(['doctor_id' => $doctor->id, 'from_date' => today()->addDays(3), 'to_date' => today()->addDays(4), 'reason' => 'Conference']);

        // The front desk: the day, hours, phone, leave and inpatients; not today's money.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $card = $this->getJson("/api/doctors/{$doctor->id}/card")->assertOk()
            ->assertJsonPath('doctor.name', 'Dr Sample Card')->assertJsonPath('doctor.phone', '9000000001')->assertJsonPath('doctor.hours_today.0.from', '09:00')
            ->assertJsonPath('today.waiting', 1)->assertJsonPath('today.seen', 1)->assertJsonPath('today.booked', 0)
            ->assertJsonPath('today.queue.0.id', $waiting->id)->assertJsonPath('today.queue.0.patient.name', 'Sample Waiting')
            ->assertJsonPath('away.0.reason', 'Conference')->assertJsonPath('inpatients.0.id', $stay->id)->assertJsonPath('inpatients.0.bed', 'General Ward · Bed 7')
            ->assertJsonPath('reps_waiting', [])->json();
        $this->assertArrayNotHasKey('money_today', $card);
        $this->assertArrayNotHasKey('results_unseen', $card);

        // The admin also sees what the doctor has brought in today.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson("/api/doctors/{$doctor->id}/card")->assertOk()->assertJsonPath('money_today.collected', 0)->assertJsonPath('results_unseen', 0);

        // A login that only keeps its own attendance cannot open it; a name that is not a doctor's is not a doctor card.
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson("/api/doctors/{$doctor->id}/card")->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/doctors/'.User::factory()->create(['role' => 'nurse'])->id.'/card')->assertNotFound();
    }

    public function test_a_medicine_opens_its_own_page_with_stock_suppliers_sales_brands_and_offers(): void
    {
        $paracetamol = \App\Models\Medicine::create(['name' => 'Sample Para 650', 'composition' => 'Paracetamol 650mg', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 20]);
        $other = \App\Models\Medicine::create(['name' => 'Other Para 650', 'composition' => ' paracetamol 650MG', 'gst_percent' => 12, 'units_per_pack' => 10]);
        \App\Models\Medicine::create(['name' => 'Para 500', 'composition' => 'Paracetamol 500mg', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $stockist = \App\Models\Stockist::create(['name' => 'Sample Pharma Distributors']);
        $invoice = \App\Models\PurchaseInvoice::create(['stockist_id' => $stockist->id, 'invoice_no' => 'INV-77', 'invoice_date' => today()->subDays(5), 'total' => 300]);
        $batch = \App\Models\StockBatch::create(['medicine_id' => $paracetamol->id, 'purchase_invoice_id' => $invoice->id, 'batch_no' => 'PB1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 10,
            'pack_price' => 30, 'pack_mrp' => 50, 'unit_mrp' => 5, 'gst_percent' => 12, 'units_received' => 100, 'units_in_stock' => 100, 'rack_no' => 'R2']);
        $company = \App\Models\RepCompany::create(['name' => 'Sample Labs']);
        \App\Models\RepProduct::create(['rep_company_id' => $company->id, 'name' => 'Labs Para', 'composition' => 'Paracetamol 650mg', 'rate' => 22, 'mrp' => 45, 'scheme' => '10+1']);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $patient = Patient::factory()->create();
        $this->postJson('/api/pharmacy/sales', ['patient_id' => $patient->id, 'customer_name' => $patient->name, 'paid' => 30, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $batch->id, 'quantity' => 6]]])->assertCreated();

        $page = $this->getJson("/api/pharmacy/medicines/{$paracetamol->id}/page")->assertOk()
            ->assertJsonPath('medicine.name', 'Sample Para 650')->assertJsonPath('on_shelf', 94)->assertJsonPath('batches.0.batch_no', 'PB1')->assertJsonPath('batches.0.rack_no', 'R2')
            ->assertJsonPath('suppliers.0.name', 'Sample Pharma Distributors')->assertJsonPath('suppliers.0.last_rate', 30)->assertJsonPath('purchases.0.invoice_no', 'INV-77')
            ->assertJsonPath('sales.month.units', 6)->assertJsonPath('sales.recent.0.patient_id', $patient->id)
            ->assertJsonPath('offers.0.name', 'Labs Para')->assertJsonPath('offers.0.rate', 22)->json();
        // Same contents however the letters were typed; a different strength is not the same medicine.
        $this->assertSame([$other->id], array_column($page['alternatives'], 'id'));

        // Someone with no work in the pharmacy cannot open it.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/pharmacy/medicines/{$paracetamol->id}/page")->assertForbidden();
    }

    public function test_the_top_search_finds_numbers_medicines_doctors_and_reps_for_those_whose_work_opens_them(): void
    {
        $patient = Patient::factory()->create(['name' => 'Sample Searched']);
        $bill = \App\Models\Bill::create(['patient_id' => $patient->id, 'kind' => 'other', 'created_by' => User::factory()->create()->id]);
        $order = \App\Models\LabOrder::create(['patient_id' => $patient->id, 'bill_id' => $bill->id, 'ordered_at' => now()]);
        $stay = Admission::create(['patient_id' => $patient->id, 'doctor_id' => User::factory()->create(['role' => 'doctor'])->id, 'admitted_at' => now(), 'status' => 'admitted']);
        $medicine = \App\Models\Medicine::create(['name' => 'Searchomycin 250', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Searchable']);
        $rep = \App\Models\MedicalRep::create(['name' => 'Searchable Rep', 'rep_company_id' => \App\Models\RepCompany::create(['name' => 'Sample Co'])->id]);
        $number = (int) substr($bill->bill_no, 1);

        // The admin finds each number typed short or in full, whatever the case.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/search?q=b'.$number)->assertOk()->assertJsonCount(1)->assertJsonPath('0.kind', 'bill')->assertJsonPath('0.to', "/front-desk/bills?open={$bill->id}");
        $this->getJson('/api/search?q='.$order->order_no)->assertJsonPath('0.kind', 'lab_order')->assertJsonPath('0.to', "/lab?open={$order->id}");
        $this->getJson('/api/search?q='.$stay->ipd_no)->assertJsonPath('0.kind', 'ipd')->assertJsonPath('0.to', "/ipd/{$stay->id}");
        $this->getJson('/api/search?q=searcho')->assertJsonPath('0.kind', 'medicine')->assertJsonPath('0.to', "/pharmacy/medicines/{$medicine->id}");
        $found = collect($this->getJson('/api/search?q=searchable')->assertOk()->json());
        $this->assertSame($doctor->id, $found->firstWhere('kind', 'doctor')['doctor_id']);
        $this->assertSame('/reps?tab=reps&q=Searchable%20Rep', $found->firstWhere('kind', 'rep')['to']);

        // The pharmacist finds medicines but no front desk bill, IPD case or rep.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson('/api/search?q=B'.$number)->assertOk()->assertJsonCount(0);
        $this->getJson('/api/search?q='.$stay->ipd_no)->assertJsonCount(0);
        $this->assertSame(['medicine'], array_column($this->getJson('/api/search?q=searcho')->json(), 'kind'));
        $this->assertNotContains('rep', array_column($this->getJson('/api/search?q=searchable')->json(), 'kind'));
        // A nurse opens a lab order as its report; a doctor, who also works in the lab, opens it there.
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->getJson('/api/search?q='.$order->order_no)->assertJsonPath('0.to', "/lab/orders/{$order->id}/print")->assertJsonPath('0.new_tab', true);
        // A login that only keeps its own attendance is not let in at all.
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson('/api/search?q=searchable')->assertForbidden();
    }

    public function test_report_rows_say_what_their_bill_patient_and_doctor_open(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $visit = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'reviewed']);
        $bill = \App\Models\Bill::create(['patient_id' => $patient->id, 'appointment_id' => $visit->id, 'kind' => 'appointment', 'created_by' => $doctor->id]);
        $bill->addLine('OPD consultation', 500);
        $bill->payments()->create(['type' => 'payment', 'amount' => 500, 'mode' => 'cash', 'received_by' => $doctor->id]);
        $bill->recalculate();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $day = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
        $row = $this->getJson('/api/reports/list/bills?'.http_build_query($day))->assertOk()->json('rows.0');
        $this->assertSame(['kind' => 'bill', 'id' => $bill->id], $row['_links']['bill_no']);
        $this->assertSame(['kind' => 'patient', 'id' => $patient->id], $row['_links']['patient']);
        // The words are unchanged, so printing and exporting are as before.
        $this->assertSame($bill->bill_no, $row['bill_no']);
        $this->assertSame(['kind' => 'doctor', 'id' => $doctor->id], $this->getJson('/api/reports/list/doctor-collections?'.http_build_query($day))->assertOk()->json('rows.0._links.doctor'));
    }
}
