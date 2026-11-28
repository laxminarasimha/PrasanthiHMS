<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The places a person chose for the bar at the foot of their phone, in their own order. Empty: the usual bar for their role. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'bar_tabs')) {
            Schema::table('users', fn (Blueprint $table) => $table->json('bar_tabs')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'bar_tabs')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('bar_tabs'));
        }
    }
};
