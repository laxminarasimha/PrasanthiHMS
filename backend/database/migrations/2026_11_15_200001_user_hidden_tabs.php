<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menu tabs a person has chosen not to see. It only tidies their menu; it does not change what they may do.
        Schema::table('users', fn (Blueprint $table) => $table->json('hidden_tabs')->nullable()->after('pad_hidden_sections'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('hidden_tabs'));
    }
};
