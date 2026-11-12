<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\Service;
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

        if (Patient::count() === 0) {
            Patient::factory(8)->create(['branch_id' => $branch->id]);
        }
    }
}
