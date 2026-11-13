<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_charges', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('unit_price'); // rupees off this line
            $table->string('remarks')->nullable()->after('amount');
            // A removed charge is kept, marked with who removed it and when.
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('admission_payments', function (Blueprint $table) {
            $table->string('remarks')->nullable()->after('reference');
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->string('aadhaar_no', 12)->nullable()->after('attendant_phone');
            $table->string('gst_no', 20)->nullable()->after('aadhaar_no');
        });

        Schema::table('discharge_summaries', function (Blueprint $table) {
            $table->text('treatment_given')->nullable()->after('investigations');
            $table->text('operative_findings')->nullable()->after('procedures');
            $table->text('tests_requested')->nullable()->after('advice');
            $table->text('other_instructions')->nullable()->after('tests_requested'); // nutrition, physiotherapy and the like
            $table->text('acknowledgement')->nullable()->after('other_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('discharge_summaries', fn (Blueprint $table) => $table->dropColumn(['treatment_given', 'operative_findings', 'tests_requested', 'other_instructions', 'acknowledgement']));
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn(['aadhaar_no', 'gst_no']));
        Schema::table('admission_payments', fn (Blueprint $table) => $table->dropColumn('remarks'));
        Schema::table('admission_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
            $table->dropColumn(['discount', 'remarks']);
        });
    }
};
