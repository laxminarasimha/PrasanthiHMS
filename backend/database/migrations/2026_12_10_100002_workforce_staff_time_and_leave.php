<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce module, part 2 of 3: the people, their working hours and their leave. Every table is new.
 *
 * A member of staff here is an employee of the hospital, whether or not they have a login to this system: the
 * login (`users`) and the employee are linked when both exist, and kept apart because many employees never sign in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wf_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->time('starts_at');
            $table->time('ends_at'); // earlier than starts_at means the shift ends the next morning
            $table->unsignedSmallInteger('grace_minutes')->default(0); // late only after this
            $table->unsignedSmallInteger('early_leave_minutes')->default(0); // leaving this much early is not counted
            $table->unsignedSmallInteger('break_minutes')->default(0); // unpaid break taken off the hours worked
            $table->unsignedSmallInteger('half_day_minutes')->nullable(); // worked at least this: half a day
            $table->unsignedSmallInteger('full_day_minutes')->nullable(); // worked at least this: a full day
            $table->unsignedSmallInteger('overtime_after_minutes')->nullable(); // null: no overtime is counted
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('wf_holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });
        Schema::create('wf_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('wf_holiday_calendars')->cascadeOnDelete();
            $table->date('on');
            $table->string('name', 120);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();
            $table->unique(['calendar_id', 'on']);
        });

        Schema::create('wf_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('employee_no', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // their login, if they have one
            $table->string('status', 20)->default('active')->index(); // active | on_notice | inactive
            $table->string('name', 150)->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('admin_entries')->nullOnDelete(); // the hospital's own list of departments
            $table->string('designation', 100)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('wf_employees')->nullOnDelete(); // who they report to
            $table->string('work_location', 100)->nullable();
            $table->date('joined_on')->nullable();
            $table->date('confirmed_on')->nullable();
            $table->date('separated_on')->nullable();
            $table->string('separation_reason', 255)->nullable();
            $table->string('employment_type', 20)->default('permanent'); // permanent | contract | part_time | consultant | trainee
            $table->foreignId('shift_id')->nullable()->constrained('wf_shifts')->nullOnDelete();
            $table->json('weekly_off')->nullable(); // days of the week, e.g. ["sun"]
            $table->foreignId('holiday_calendar_id')->nullable()->constrained('wf_holiday_calendars')->nullOnDelete();
            $table->string('payment_mode', 20)->nullable(); // bank | cash | upi | cheque
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'employee_no']);
            $table->unique(['branch_id', 'user_id']);
        });

        // Bank, tax and statutory details. Kept in a table of their own so that ordinary lists never load them,
        // readable only with a permission of their own, and every number is stored encrypted.
        Schema::create('wf_employee_private', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('wf_employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('bank_name', 120)->nullable();
            $table->text('bank_account_no')->nullable(); // encrypted
            $table->text('bank_ifsc')->nullable(); // encrypted
            $table->text('upi_id')->nullable(); // encrypted
            $table->text('pan')->nullable(); // encrypted
            $table->text('aadhaar')->nullable(); // encrypted
            $table->text('uan')->nullable(); // encrypted (provident fund)
            $table->text('esi_no')->nullable(); // encrypted
            $table->string('tax_regime', 20)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Who looks after which department: what "the staff of my department" means for a department manager.
        Schema::create('wf_department_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('department_id')->constrained('admin_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
        });

        // One spell of work: the day it belongs to is the day the shift started, even when it ended after midnight.
        Schema::create('wf_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained('wf_shifts')->nullOnDelete(); // the shift that applied that day
            $table->dateTime('check_in')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->string('state', 20)->default('open'); // open | completed | review (times that do not make sense)
            $table->string('day_status', 20)->nullable(); // set by a person where the times alone do not say: present | half_day | absent
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('origin', 20)->default('manual'); // manual | self | import | migrated
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'work_date']);
            $table->index(['branch_id', 'work_date']);
            $table->unique(['employee_id', 'check_in']);
        });

        // A change someone asks for, or makes, to a spell of work: always with a reason, and what it was and became.
        Schema::create('wf_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->foreignId('attendance_id')->nullable()->constrained('wf_attendance')->nullOnDelete(); // null: asking for a day to be added
            $table->date('work_date');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled | applied (made directly)
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        // A sheet of attendance brought in from a machine or a spreadsheet: looked over first, then applied.
        Schema::create('wf_attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('file_name', 191)->nullable();
            $table->char('sha256', 64);
            $table->string('status', 20)->default('preview'); // preview | applied | abandoned
            $table->json('summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wf_attendance_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('wf_attendance_imports')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->char('source_key', 64); // the same punch from the same source is the same row, whenever it arrives
            $table->char('payload_hash', 64);
            $table->string('employee_ref', 64)->nullable(); // as written in the sheet
            $table->string('staff_name', 191)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('wf_employees')->nullOnDelete();
            $table->date('work_date')->nullable();
            $table->dateTime('check_in')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->string('state', 20); // ready | duplicate | unmatched | missing_in | missing_out | invalid | applied | linked
            $table->foreignId('attendance_id')->nullable()->constrained('wf_attendance')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'source_key']);
        });

        Schema::create('wf_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->boolean('is_paid')->default(true);
            $table->decimal('annual_allowance', 6, 2)->default(0); // days in a leave year
            $table->string('accrual', 20)->default('yearly'); // yearly (all at the start) | monthly | none
            $table->decimal('carry_forward_max', 6, 2)->default(0); // days that may go into next year
            $table->unsignedSmallInteger('carry_forward_expires_months')->nullable(); // carried days lapse after this
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('needs_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('wf_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->foreignId('leave_type_id')->constrained('wf_leave_types');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('from_part', 10)->default('full'); // full | first_half | second_half
            $table->string('to_part', 10)->default('full');
            $table->decimal('days', 6, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'from_date', 'to_date']);
            $table->index(['branch_id', 'status']);
        });

        // The leave account. A balance is never typed over: every day earned, taken, carried, lapsed or corrected is
        // a line, and the balance is their sum.
        Schema::create('wf_leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->foreignId('leave_type_id')->constrained('wf_leave_types');
            $table->date('entry_date');
            $table->unsignedSmallInteger('leave_year');
            $table->string('kind', 20); // opening | accrual | taken | carry_forward | expiry | adjustment | reversal
            $table->decimal('days', 6, 2); // plus adds to the balance, minus takes from it
            $table->foreignId('leave_request_id')->nullable()->constrained('wf_leave_requests')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('once_key', 120)->nullable(); // stops the same accrual or expiry being written twice
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'leave_type_id', 'leave_year']);
            $table->unique(['employee_id', 'once_key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'wf_leave_ledger', 'wf_leave_requests', 'wf_leave_types', 'wf_attendance_import_rows', 'wf_attendance_imports', 'wf_attendance_corrections',
            'wf_attendance', 'wf_department_managers', 'wf_employee_private', 'wf_employees', 'wf_holidays', 'wf_holiday_calendars', 'wf_shifts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
