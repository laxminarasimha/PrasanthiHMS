<?php

use App\Support\DoctorList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's rule: a consultant on the staff list is a doctor, and is offered wherever doctors are chosen. From
 * now on the staff form does this as a consultant is added. This does it once for the consultants added before:
 * each is given the doctor's record that cannot be signed in with, exactly as the "Put all on the doctors' list"
 * button on the staff check does. Nothing else is changed, and anyone can be taken off again from their record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wf_employees') || ! Schema::hasColumn('users', 'sign_in_with') || ! Schema::hasColumn('users', 'book_online')) {
            return;
        }
        $done = DoctorList::catchUp();
        if ($done > 0 && app()->runningInConsole()) {
            echo "  {$done} consultant(s) on the staff list were put on the list of doctors.\n";
        }
    }

    public function down(): void
    {
        // Nobody is taken off the list of doctors by going back: their appointments and fees name them.
    }
};
