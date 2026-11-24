<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing in with a mobile number, and logins for staff who need only their own record.
 * Structure only: no row is read or changed. A login's mobile number is taken up the next time that login is
 * saved or used (App\Support\StaffLogin), so existing logins carry on exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'login_mobile')) {
                // The ten digits this person signs in with. One login per number: it is unique, and empty where two logins share a phone.
                $table->string('login_mobile', 15)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'sign_in_with')) {
                $table->string('sign_in_with', 10)->default('password'); // password | pin
            }
            if (! Schema::hasColumn('users', 'must_change_secret')) {
                // Set when the hospital hands out a starting PIN: nothing else opens until the person has chosen their own.
                $table->boolean('must_change_secret')->default(false);
            }
            if (! Schema::hasColumn('users', 'is_shared_screen')) {
                // A login for a screen on the wall (the waiting-room board), which is not a member of staff.
                $table->boolean('is_shared_screen')->default(false);
            }
        });

        // Many staff have no email address. A login without one signs in with its mobile number.
        $email = collect(Schema::getColumns('users'))->firstWhere('name', 'email');
        if ($email && ! $email['nullable']) {
            Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['login_mobile', 'sign_in_with', 'must_change_secret', 'is_shared_screen'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    if ($column === 'login_mobile') {
                        $table->dropUnique(['login_mobile']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
