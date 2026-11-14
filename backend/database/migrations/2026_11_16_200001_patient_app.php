<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A patient's own login for the mobile app. It is separate from staff logins on purpose.
        Schema::create('patient_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mobile', 15)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            // Set by staff once they have seen the person, or later by an OTP: until then the
            // account can book and subscribe but is not joined to older hospital records.
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        // Regular medicines a patient wants kept ready every month.
        Schema::create('medicine_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->json('items'); // [{medicine_id, name, quantity}]
            $table->unsignedTinyInteger('day_of_month'); // 1 to 28
            $table->string('status', 20)->default('active'); // active, paused, cancelled
            $table->date('next_due_on');
            $table->date('last_prepared_on')->nullable();
            $table->foreignId('last_prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('appointments', fn (Blueprint $table) => $table->string('booked_via', 20)->nullable()->after('notes')); // "app" when the patient booked it themselves
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('booked_via'));
        Schema::dropIfExists('medicine_subscriptions');
        Schema::dropIfExists('patient_accounts');
    }
};
