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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->index()->constrained()->restrictOnDelete();
            $table->string('gateway');
            $table->string('gateway_subscription_id');
            $table->integer('amount_cents');
            // Lowercase ISO code, as the gateway sends it
            $table->string('currency', 3)->default('usd');
            // month or year
            $table->string('interval');
            // incomplete, active, past_due, unpaid, paused, or canceled
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            // Copy of the latest gateway object
            $table->jsonb('gateway_data')->nullable();
            $table->timestamps();
            // Subscriptions are soft-deleted, never removed
            $table->softDeletes();

            // Unique among soft-deleted subscriptions too, so a re-import
            // never creates a duplicate
            $table->unique(['gateway', 'gateway_subscription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
