<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sign in with Google": the Google account joined to a staff login or to a patient's app account. Google's own id for
 * the account is what is matched, since an email address can change hands; the email is kept to show which one it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'patient_accounts'] as $table) {
            if (! Schema::hasColumn($table, 'google_sub')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('google_sub', 64)->nullable()->unique();
                    $t->string('google_email', 255)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'patient_accounts'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_google_sub_unique');
                $t->dropColumn(['google_sub', 'google_email']);
            });
        }
    }
};
