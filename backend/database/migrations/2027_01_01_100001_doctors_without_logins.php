<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor on the staff list is a doctor everywhere, whether or not they sign in. Structure only: one column that
 * says whether patients may book a doctor from the phone app. It starts as "yes" for every existing doctor, which
 * is how it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'book_online')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('book_online')->default(true));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'book_online')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('book_online'));
        }
    }
};
