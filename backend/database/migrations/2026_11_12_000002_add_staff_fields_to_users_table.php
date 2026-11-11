<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 20)->default('frontdesk')->after('email');
            $table->string('gender', 10)->nullable()->after('role');
            $table->string('phone', 20)->nullable()->after('gender');
            $table->string('department')->nullable()->after('phone');
            $table->string('signature_text')->nullable()->after('department');
            $table->boolean('is_active')->default(true)->after('signature_text');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['role', 'gender', 'phone', 'department', 'signature_text', 'is_active']);
        });
    }
};
