<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Only what differs from the person's role: {"canRunLab": true, "canDeleteBills": false}.
            $table->json('permissions')->nullable()->after('role');
            // A picture of the signature, kept on the private disk, printed on prescriptions and lab reports.
            $table->string('signature_path')->nullable()->after('signature_text');
            // A doctor's usual hours: {"mon": [{"from": "10:30", "to": "14:00"}], ...}. Empty means the hospital's OPD hours.
            $table->json('availability')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['permissions', 'signature_path', 'availability']));
    }
};
