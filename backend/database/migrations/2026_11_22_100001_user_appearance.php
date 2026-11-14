<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each person's own choice of colours and text size. It follows them to any computer they sign in on.
        Schema::table('users', fn (Blueprint $table) => $table->json('appearance')->nullable()->after('hidden_tabs'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('appearance'));
    }
};
