<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PurchaseInvoice;
use App\Models\Service;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data only: one branch, an admin and a doctor login, sample services and a few made-up patients.
     * Set SEED_ADMIN_PASSWORD in .env to choose the admin password.
     */
    public function run(): void
    {
        $branch = Branch::firstOrCreate(['name' => 'Prasanthi Hospitals'], ['city' => 'Vijayawada']);

        User::firstOrCreate(['email' => 'admin@prasanthi.test'], [
            'name' => 'Admin',
            'password' => env('SEED_ADMIN_PASSWORD', 'ChangeMe@123'),
            'role' => 'admin',
            'gender' => 'male',
            'phone' => '9000000000',
            'branch_id' => $branch->id,
        ]);

        $doctor = User::firstOrCreate(['email' => 'doctor@prasanthi.test'], [
            'name' => 'Dr Sample Doctor',
            'password' => env('SEED_ADMIN_PASSWORD', 'ChangeMe@123'),
            'role' => 'doctor',
            'gender' => 'male',
            'phone' => '9000000001',
            'department' => 'General Medicine',
            'branch_id' => $branch->id,
        ]);

        if (Service::count() === 0) {
            Service::create(['name' => 'Consultation', 'type' => 'appointment', 'price' => 200, 'doctor_id' => $doctor->id]);
            Service::create(['name' => 'Follow up', 'type' => 'appointment', 'price' => 0, 'doctor_id' => $doctor->id]);
            Service::create(['name' => 'Dressing', 'type' => 'other', 'price' => 150]);
            Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300]);
        }

        if (LabTest::count() === 0) {
            $catalogue = [
                ['Random Blood Sugar (RBS)', 'Biochemistry', 'Blood', 80, [['Random Blood Sugar', 'mg/dL', '70 - 140']]],
                ['Fasting Blood Sugar (FBS)', 'Biochemistry', 'Blood', 80, [['Fasting Blood Sugar', 'mg/dL', '70 - 100']]],
                ['Serum Creatinine', 'Biochemistry', 'Blood', 150, [['Serum Creatinine', 'mg/dL', '0.6 - 1.3']]],
                ['Complete Blood Picture (CBP)', 'Haematology', 'Blood', 300, [
                    ['Haemoglobin', 'g/dL', '12 - 16'], ['Total WBC Count', 'cells/cumm', '4000 - 11000'],
                    ['Platelet Count', 'lakhs/cumm', '1.5 - 4.5'], ['PCV', '%', '36 - 46'],
                ]],
                ['Lipid Profile', 'Biochemistry', 'Blood', 500, [
                    ['Total Cholesterol', 'mg/dL', '0 - 200'], ['Triglycerides', 'mg/dL', '0 - 150'],
                    ['HDL Cholesterol', 'mg/dL', '40 - 60'], ['LDL Cholesterol', 'mg/dL', '0 - 100'],
                ]],
                ['Thyroid Stimulating Hormone (TSH)', 'Hormones', 'Blood', 350, [['TSH', 'µIU/mL', '0.4 - 4.0']]],
                ['Complete Urine Examination (CUE)', 'Clinical Pathology', 'Urine', 150, [
                    ['Colour', null, 'Pale yellow'], ['Albumin', null, 'Nil'], ['Sugar', null, 'Nil'], ['Pus Cells', '/hpf', '0 - 5'],
                ]],
            ];
            foreach ($catalogue as $order => [$name, $department, $sample, $price, $parameters]) {
                $test = LabTest::create(['name' => $name, 'department' => $department, 'sample_type' => $sample, 'price' => $price, 'print_order' => $order + 1]);
                foreach ($parameters as $position => [$parameter, $unit, $range]) {
                    $test->parameters()->create(['name' => $parameter, 'unit' => $unit, 'reference_range' => $range, 'position' => $position]);
                }
            }
        }

        if (Medicine::count() === 0) {
            $stockist = Stockist::create(['name' => 'Sample Medical Agencies', 'phone' => '9000000002']);
            $invoice = PurchaseInvoice::create([
                'branch_id' => $branch->id, 'stockist_id' => $stockist->id, 'invoice_no' => 'SAMPLE-001', 'invoice_date' => today(),
            ]);
            // name, type, manufacturer, units per pack, packs, pack price, pack MRP, GST %, months to expiry
            $medicines = [
                ['Paracetamol 650mg Tablet', 'Tablet', 'Sample Pharma', 10, 30, 15, 28, 12, 18],
                ['Cetirizine 10mg Tablet', 'Tablet', 'Sample Pharma', 10, 20, 9, 18, 12, 24],
                ['Pantoprazole 40mg Tablet', 'Tablet', 'Sample Labs', 15, 20, 62, 110, 12, 14],
                ['Amoxicillin 500mg Capsule', 'Capsule', 'Sample Labs', 10, 15, 48, 85, 12, 3],
                ['Cough Syrup 100ml', 'Syrup', 'Sample Remedies', 1, 24, 58, 95, 12, 20],
                ['ORS Sachet', 'Sachet', 'Sample Remedies', 1, 50, 14, 22, 5, 30],
            ];
            $subtotal = $tax = 0;
            foreach ($medicines as $i => [$name, $type, $maker, $perPack, $packs, $price, $mrp, $gst, $months]) {
                $medicine = Medicine::create(['name' => $name, 'type' => $type, 'manufacturer' => $maker, 'gst_percent' => $gst, 'units_per_pack' => $perPack, 'reorder_level' => $perPack * 5]);
                $invoice->batches()->create([
                    'medicine_id' => $medicine->id, 'batch_no' => 'SMP'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'expiry' => today()->addMonths($months)->endOfMonth(), 'units_per_pack' => $perPack, 'packs' => $packs,
                    'pack_price' => $price, 'pack_mrp' => $mrp, 'unit_mrp' => round($mrp / $perPack, 2), 'gst_percent' => $gst,
                    'units_received' => $packs * $perPack, 'units_in_stock' => $packs * $perPack,
                ]);
                $subtotal += $packs * $price;
                $tax += round($packs * $price * $gst / 100, 2);
            }
            $invoice->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
        }

        if (Patient::count() === 0) {
            Patient::factory(8)->create(['branch_id' => $branch->id]);
        }
    }
}
