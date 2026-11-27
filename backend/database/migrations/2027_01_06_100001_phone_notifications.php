<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones and computers a person has asked to be notified on. One row is one browser or installed app:
 * the address its notifications are sent to, and the two keys only that device can read them with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // The address is too long to be made unique itself.
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('device', 60)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
