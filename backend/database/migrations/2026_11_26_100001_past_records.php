<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One old paper a patient brought: a prescription, a lab report, a discharge summary. It is scanned,
        // read by a machine into a draft, checked by a person, and only then filed in the patient's record.
        Schema::create('past_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('other');
            $table->date('record_date')->nullable();
            $table->string('doctor_name')->nullable();
            $table->string('hospital_name')->nullable();
            $table->string('status', 20)->default('to_check'); // to_check, filed
            $table->string('read_state', 20)->default('none'); // none, queued, reading, done, failed
            $table->string('read_engine', 20)->nullable(); // marker, ai
            $table->longText('read_text')->nullable();
            $table->string('read_error', 500)->nullable();
            $table->dateTime('read_claimed_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('ai_sent_at')->nullable();
            $table->json('draft')->nullable(); // what the machine read, or what a person saved half-way
            $table->json('details')->nullable(); // what a person checked and filed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
            $table->index(['status', 'read_state']);
        });

        // Test values copied from an old report, so they line up with the hospital's own results over time.
        Schema::create('past_record_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('past_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('test_name');
            $table->string('parameter');
            $table->string('value');
            $table->string('unit', 30)->nullable();
            $table->string('reference_range')->nullable();
            $table->string('flag', 10)->nullable();
            $table->date('taken_on');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['patient_id', 'taken_on']);
        });

        // The scanned pages themselves are attachments that belong to a past record.
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('past_record_id')->nullable()->after('appointment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_no')->default(0)->after('past_record_id');
        });

        // A lasting fact that came from an old paper keeps a link to it.
        Schema::table('patient_health_items', function (Blueprint $table) {
            $table->foreignId('past_record_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_health_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('past_record_id'));
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('past_record_id');
            $table->dropColumn('page_no');
        });
        Schema::dropIfExists('past_record_results');
        Schema::dropIfExists('past_records');
    }
};
