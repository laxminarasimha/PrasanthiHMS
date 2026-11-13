<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small hospital-wide lists: departments, vendors, and payers (insurers and companies that settle bills).
        Schema::create('admin_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // department, vendor, payer
            $table->string('name');
            $table->json('details')->nullable(); // the extra boxes each kind has
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        Schema::table('admissions', function (Blueprint $table) {
            // Who settles the bill when it is not the patient: an insurer, a company, a scheme.
            $table->foreignId('payer_id')->nullable()->after('doctor_id')->constrained('admin_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('payer_id'));
        Schema::dropIfExists('admin_entries');
    }
};
