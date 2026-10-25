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
        // Every incoming payment gateway webhook, for deduplication,
        // debugging, and replay. Append-only; never deleted, so no
        // deleted_at column
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_id');
            $table->string('type')->index();
            $table->jsonb('payload');
            // Processing status
            $table->timestamp('processed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            // A redelivered event is stored once
            $table->unique(['gateway', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
