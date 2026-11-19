<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce phase 4. The hospital's existing expenses gain stages (a draft, sent for approval, approved or refused,
 * paid), a configured heading with its approval limits, a cost centre, the tax inside the amount, a receipt, and a
 * link to what posted them (a payroll payment or an advance). Every entry already there is money already paid, so
 * the new `status` column starts as "paid" for all of them and `paid_on` is the date they were entered for: nothing
 * they say is changed, and every total they make stays the same.
 *
 * A payslip also keeps what was actually paid out on it, so a payroll run again after a reversal posts only what
 * had not already been paid, and salaries are never counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'status')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('status', 20)->default('paid')->index()->after('expense_no'); // draft | submitted | approved | rejected | paid
                $table->foreignId('category_id')->nullable()->after('category')->constrained('wf_expense_categories')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->after('category_id')->constrained('admin_entries')->nullOnDelete();
                $table->string('bill_no', 60)->nullable()->after('paid_to');
                $table->decimal('tax', 12, 2)->default(0)->after('amount'); // the tax inside the amount, not on top of it
                $table->date('paid_on')->nullable()->index()->after('mode');
                $table->foreignId('paid_by')->nullable()->after('paid_on')->constrained('users')->nullOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('submitted_at')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->string('receipt_path')->nullable();
                $table->string('receipt_name')->nullable();
                $table->string('receipt_mime', 100)->nullable();
                $table->string('source_type', 30)->nullable(); // payroll_payment | advance | template
                $table->unsignedBigInteger('source_id')->nullable();
                $table->index(['source_type', 'source_id']);
            });
        }
        // Money already paid: the day it was paid is the day it was entered for.
        DB::table('expenses')->whereNull('paid_on')->where('status', 'paid')->update(['paid_on' => DB::raw('spent_on')]);

        if (! Schema::hasColumn('wf_expense_categories', 'approver_limit')) {
            Schema::table('wf_expense_categories', function (Blueprint $table) {
                // Above this an expense needs the higher approval ("Approve large expenses"). Null: no upper limit.
                $table->decimal('approver_limit', 12, 2)->nullable()->after('approval_above');
                $table->unsignedSmallInteger('sort')->default(0)->after('is_active');
            });
        }
        if (! Schema::hasColumn('wf_advance_ledger', 'mode')) {
            Schema::table('wf_advance_ledger', function (Blueprint $table) {
                // How money changed hands, for a line where it did: cash given or paid back in cash belongs in the day's count.
                $table->string('mode', 20)->nullable()->after('kind');
            });
        }
        if (! Schema::hasColumn('wf_payslips', 'paid_amount')) {
            Schema::table('wf_payslips', function (Blueprint $table) {
                $table->decimal('paid_amount', 12, 2)->nullable()->after('paid_on');
            });
        }
    }

    public function down(): void
    {
        Schema::table('wf_payslips', fn (Blueprint $table) => $table->dropColumn('paid_amount'));
        Schema::table('wf_advance_ledger', fn (Blueprint $table) => $table->dropColumn('mode'));
        Schema::table('wf_expense_categories', fn (Blueprint $table) => $table->dropColumn(['approver_limit', 'sort']));
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            foreach (['category_id', 'department_id', 'paid_by', 'submitted_by', 'decided_by'] as $key) {
                $table->dropConstrainedForeignId($key);
            }
            $table->dropColumn(['status', 'bill_no', 'tax', 'paid_on', 'submitted_at', 'decided_at', 'decision_note', 'receipt_path', 'receipt_name', 'receipt_mime', 'source_type', 'source_id']);
        });
    }
};
