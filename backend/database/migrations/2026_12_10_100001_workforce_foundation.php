<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce module, part 1 of 3: what everything else in it stands on. Settings kept per branch, the record of who
 * did what (append-only, with before and after), and the tables that bring data in from an older system without
 * ever bringing the same thing in twice. Every table here is new; nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The module's choices for one branch: rounding, the working week, which statutory parts are switched on.
        Schema::create('wf_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'key']);
        });

        // Who did what, when, to whose record, why, and what it was before and after. Rows are only ever added.
        // Each row carries a hash of itself and of the row before it, so a row changed or removed behind the
        // system's back can be detected.
        Schema::create('wf_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 150)->nullable();
            $table->string('action', 80)->index(); // e.g. payroll.finalised, staff.bank.viewed
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable()->index(); // whose record it concerns
            $table->string('reason', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // One copy taken from an older system at one moment: what file it was, its fingerprint, and what it held.
        Schema::create('wf_import_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('source', 40); // e.g. wordpress
            $table->string('label', 150)->nullable();
            $table->timestamp('taken_at'); // when the copy was made at the source
            $table->char('sha256', 64);
            $table->json('counts')->nullable(); // rows per table as found
            $table->string('status', 20)->default('staged'); // staged | dry_run | approved | imported | abandoned
            $table->json('report')->nullable(); // the dry-run reconciliation
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'source', 'sha256']);
        });

        // Every row of a snapshot, held as it was found until it is brought in, set aside, or skipped.
        Schema::create('wf_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('wf_import_snapshots')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('entity', 40); // staff | attendance | attendance_audit | ...
            $table->string('source_id', 191);
            $table->char('payload_hash', 64);
            $table->longText('payload'); // encrypted: it holds people's names and numbers
            $table->string('state', 20)->default('staged'); // staged | imported | unchanged | quarantined | skipped
            $table->string('problem', 500)->nullable(); // why it was set aside
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();
            $table->unique(['snapshot_id', 'entity', 'source_id']);
            $table->index(['branch_id', 'entity', 'state']);
        });

        // Which record here stands for which record there. Bringing the same source row in again finds this link
        // and updates the same record instead of making a second one.
        Schema::create('wf_source_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('source', 40);
            $table->string('entity', 40);
            $table->string('source_id', 191);
            $table->string('target_type', 60);
            $table->unsignedBigInteger('target_id');
            $table->char('payload_hash', 64)->nullable(); // what the source row looked like when last brought in
            $table->timestamps();
            $table->unique(['branch_id', 'source', 'entity', 'source_id'], 'wf_source_links_once');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        foreach (['wf_source_links', 'wf_import_records', 'wf_import_snapshots', 'wf_audit_events', 'wf_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
