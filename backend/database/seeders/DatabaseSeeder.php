<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data only: one branch, one admin login and a few made-up patients.
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

        if (Patient::count() === 0) {
            Patient::factory(8)->create(['branch_id' => $branch->id]);
        }
    }
}
