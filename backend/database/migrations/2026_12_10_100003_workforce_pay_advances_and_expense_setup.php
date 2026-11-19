<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce module, part 3 of 3: what staff are paid, the monthly payroll, advances and loans, and the lists that
 * the wider expenses work will use. Every table is new. The hospital's existing `expenses` table is not touched
 * here; it gains its approval steps in the expenses phase, on its own, so that today's expense screens cannot be
 * affected by this change.
 *
 * No rate of any statutory deduction is written anywhere in this file. A statutory part exists as a name, switched
 * off, until the hospital's accountant enters its rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The parts a salary is made of. The hospital names its own; statutory ones start switched off and empty.
        Schema::create('wf_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('kind', 20); // earning | deduction | employer (paid by the hospital, not out of the salary)
            $table->string('basis', 20)->default('fixed'); // fixed | percent_of_basic | percent_of_gross | per_day | per_hour
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('reduces_with_absence')->default(true); // an earning that shrinks with unpaid days
            $table->json('rule')->nullable(); // for a statutory part: the percentage, ceiling and slabs the accountant entered
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        // What one person is paid, from a date. A rise is a new row with a new date; the old one is kept, so a
        // payroll for an earlier month still finds the pay that applied then.
        Schema::create('wf_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('pay_basis', 20)->default('monthly'); // monthly | daily | hourly
            $table->decimal('overtime_rate', 12, 2)->nullable(); // per hour; null: no overtime is paid
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });
        Schema::create('wf_salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('wf_salary_structures')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('wf_salary_components');
            $table->decimal('amount', 12, 2)->nullable(); // for a fixed, per-day or per-hour part
            $table->decimal('percent', 7, 4)->nullable(); // for a percentage part
            $table->timestamps();
            $table->unique(['structure_id', 'component_id']);
        });

        // One month's payroll for a branch (or for some of its staff). It moves one way: draft, reviewed, approved,
        // finalised, paid. Once finalised nothing in it changes; a mistake is put right by reversing it.
        Schema::create('wf_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->char('period', 7); // YYYY-MM
            $table->date('period_start');
            $table->date('period_end');
            $table->string('label', 120)->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft | reviewed | approved | finalised | paid | reversed
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->json('rules')->nullable(); // the settings the run was worked out with, frozen at finalising
            $table->foreignId('reversal_of_id')->nullable()->constrained('wf_payroll_runs')->nullOnDelete();
            $table->string('reverse_reason', 500)->nullable();
            foreach (['created', 'reviewed', 'approved', 'finalised', 'reversed'] as $step) {
                $table->foreignId("{$step}_by")->nullable()->constrained('users')->nullOnDelete();
                if ($step !== 'created') {
                    $table->timestamp("{$step}_at")->nullable();
                }
            }
            $table->unsignedBigInteger('posted_expense_id')->nullable(); // the one expense entry that stands for this payroll in the accounts
            $table->timestamps();
            $table->index(['branch_id', 'period']);
        });

        // One person's pay for one period. `live_period` holds the period while the payslip stands and is emptied
        // when it is reversed, so the database itself refuses a second standing payslip for the same person and month.
        Schema::create('wf_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('run_id')->constrained('wf_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->char('period', 7);
            $table->char('live_period', 7)->nullable();
            $table->string('slip_no', 30)->nullable();
            $table->json('days')->nullable(); // days in the month, paid, present, on leave, absent, holidays, weekly offs, overtime hours
            $table->json('pay_as_set')->nullable(); // the salary structure it was worked out from, as it stood
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->decimal('employer_cost', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid'); // unpaid | paid | held
            $table->date('paid_on')->nullable();
            $table->string('payment_mode', 20)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'live_period'], 'wf_payslips_one_per_month');
            $table->index(['branch_id', 'period']);
        });
        Schema::create('wf_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('wf_payslips')->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('wf_salary_components')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('kind', 20); // earning | deduction | employer
            $table->decimal('amount', 12, 2);
            $table->string('explanation', 500); // how the amount was arrived at, in words and figures
            $table->string('source_type', 40)->nullable(); // e.g. advance_schedule
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Money given to a member of staff ahead of salary, or lent, to be taken back from later salaries.
        Schema::create('wf_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->string('advance_no', 30)->nullable();
            $table->string('kind', 10)->default('advance'); // advance | loan
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('requested')->index(); // requested | approved | rejected | issued | closed | written_off
            $table->date('issued_on')->nullable();
            $table->string('payment_mode', 20)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->unsignedSmallInteger('instalments')->default(1);
            $table->char('first_period', 7)->nullable(); // the first salary month it is taken back from
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wf_advance_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_id')->constrained('wf_advances')->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('due'); // due | deducted | skipped | waived
            $table->foreignId('payslip_id')->nullable()->constrained('wf_payslips')->nullOnDelete();
            $table->timestamps();
            $table->unique(['advance_id', 'period']);
        });
        // The advance's own account: every rupee out and back is a line, and what is still owed is their sum.
        Schema::create('wf_advance_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('advance_id')->constrained('wf_advances');
            $table->date('entry_date');
            $table->string('kind', 20); // issued | payroll_deduction | repayment | waiver | write_off | reversal
            $table->decimal('amount', 12, 2); // plus: the member of staff owes more; minus: owes less
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('payslip_id')->nullable()->constrained('wf_payslips')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['advance_id', 'id']);
        });

        // What an expense is filed under, and above what amount it needs a second person's approval.
        Schema::create('wf_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 100);
            $table->decimal('approval_above', 12, 2)->nullable(); // null: every expense under it needs approval
            $table->boolean('is_payroll')->default(false); // salaries and advances: posted from payroll, never typed twice
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });
        // An expense that comes round every month. It only offers a draft to be looked at; it never posts by itself.
        Schema::create('wf_expense_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 120);
            $table->foreignId('category_id')->nullable()->constrained('wf_expense_categories')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('admin_entries')->nullOnDelete();
            $table->string('paid_to', 150)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('mode', 20)->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('last_offered_period', 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'wf_expense_templates', 'wf_expense_categories', 'wf_advance_ledger', 'wf_advance_schedule', 'wf_advances', 'wf_payslip_lines', 'wf_payslips',
            'wf_payroll_runs', 'wf_salary_structure_lines', 'wf_salary_structures', 'wf_salary_components',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
