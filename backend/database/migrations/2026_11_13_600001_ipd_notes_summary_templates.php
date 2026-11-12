<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notes written during a stay: daily clinical notes, nursing notes and operation (OT) notes.
        Schema::create('admission_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // clinical, nursing, ot
            $table->dateTime('noted_at');
            $table->text('body')->nullable();
            $table->json('details')->nullable(); // OT: procedure, surgeon, anaesthesia…; nursing: vitals
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['admission_id', 'kind', 'noted_at']);
        });

        // One discharge summary for each stay, written in parts and approved by a doctor before it is final.
        Schema::create('discharge_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('diagnosis')->nullable();
            $table->text('complaints')->nullable();
            $table->text('history')->nullable();
            $table->text('examination')->nullable();
            $table->text('investigations')->nullable();
            $table->text('procedures')->nullable();
            $table->text('course')->nullable();
            $table->text('condition_at_discharge')->nullable();
            $table->json('medicines')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('admissions', function (Blueprint $table) {
            // Once approved, the bill's charges and discount are locked until approval is withdrawn.
            $table->dateTime('bill_approved_at')->nullable()->after('discount');
            $table->foreignId('bill_approved_by')->nullable()->after('bill_approved_at')->constrained('users')->nullOnDelete();
        });

        // A named set of IPD services added to a bill in one go, such as an "Normal delivery package".
        Schema::create('ipd_bill_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('items'); // [{ipd_service_id, quantity}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipd_bill_templates');
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_approved_by');
            $table->dropColumn('bill_approved_at');
        });
        Schema::dropIfExists('discharge_summaries');
        Schema::dropIfExists('admission_notes');
    }
};
