<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Lab ----
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->string('kind', 10)->default('values')->after('name'); // values (a table of parameters) or text (a written report)
            $table->text('default_comment')->nullable()->after('print_order');
        });
        Schema::table('lab_test_parameters', fn (Blueprint $table) => $table->boolean('is_heading')->default(false)->after('name'));
        Schema::table('lab_results', fn (Blueprint $table) => $table->boolean('is_heading')->default(false)->after('parameter'));

        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->string('kind', 10)->default('values')->after('test_name');
            $table->string('package_name')->nullable()->after('kind');
            $table->text('report_text')->nullable()->after('remarks');
            $table->dateTime('collected_at')->nullable()->after('report_text');
            $table->foreignId('collected_by')->nullable()->after('collected_at')->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('reported_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('lab_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('lab_package_test', function (Blueprint $table) {
            $table->foreignId('lab_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained()->cascadeOnDelete();
            $table->primary(['lab_package_id', 'lab_test_id']);
        });

        // Hospital-wide settings, one row per group (e.g. "lab").
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        // ---- Doctor ----
        // Phrases a doctor has typed before, offered again to save typing.
        Schema::create('remembered_phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20); // complaint, diagnosis or advice
            $table->string('text');
            $table->unsignedInteger('uses')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['doctor_id', 'kind', 'text']);
        });

        // A named set of medicines the doctor often gives together.
        Schema::create('rx_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('medicines');
            $table->timestamps();
        });

        // A whole visit saved as a starting point: complaints, diagnosis, medicines, advice and so on.
        Schema::create('prescription_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('content');
            $table->timestamps();
        });

        // How a doctor last prescribed each medicine, to fill in dose and frequency next time.
        Schema::create('medicine_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('medicine');
            $table->string('dose', 50)->nullable();
            $table->string('frequency', 50)->nullable();
            $table->string('timing', 30)->nullable();
            $table->string('duration', 50)->nullable();
            $table->string('instructions')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
            $table->unique(['doctor_id', 'medicine']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('pad_hidden_sections')->nullable()->after('signature_text'); // visit pad sections this doctor does not use
        });

        // Tests already reported count as approved, so old reports keep printing as they did.
        DB::table('lab_order_items')->whereNotNull('reported_at')->update(['approved_at' => DB::raw('reported_at'), 'approved_by' => DB::raw('reported_by'), 'collected_at' => DB::raw('reported_at')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pad_hidden_sections'));
        Schema::dropIfExists('medicine_defaults');
        Schema::dropIfExists('prescription_templates');
        Schema::dropIfExists('rx_groups');
        Schema::dropIfExists('remembered_phrases');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('lab_package_test');
        Schema::dropIfExists('lab_packages');
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['kind', 'package_name', 'report_text', 'collected_at', 'approved_at']);
        });
        Schema::table('lab_results', fn (Blueprint $table) => $table->dropColumn('is_heading'));
        Schema::table('lab_test_parameters', fn (Blueprint $table) => $table->dropColumn('is_heading'));
        Schema::table('lab_tests', fn (Blueprint $table) => $table->dropColumn(['kind', 'default_comment']));
    }
};
