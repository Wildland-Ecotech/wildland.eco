<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Append-only history of each recurring donation; never deleted, so no
        // deleted_at column
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            // created, renewed, payment_failed, amount_changed, paused,
            // resumed, canceled, or reactivated
            $table->string('type');
            // webhook, import, admin, or donor
            $table->string('source');
            // The event, invoice, or action that caused it
            $table->string('source_reference');
            $table->timestamp('occurred_at');
            $table->jsonb('data')->nullable();
            $table->timestamps();

            // A re-import never duplicates history. Also serves lookups by
            // subscription, its leading column
            $table->unique(['subscription_id', 'type', 'source_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
