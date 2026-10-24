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
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->index()->constrained()->restrictOnDelete();
            // Set for payments of a recurring gift
            $table->foreignId('subscription_id')->nullable()->index()->constrained()->restrictOnDelete();
            // Set for payments started on our donate page
            $table->foreignId('checkout_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('gateway');
            // The payment itself (a Stripe PaymentIntent)
            $table->string('gateway_payment_id');
            // Set for subscription payments
            $table->string('gateway_invoice_id')->nullable()->index();
            $table->integer('amount_cents');
            // The gateway's processing fee, once known
            $table->integer('fee_cents')->nullable();
            $table->integer('refunded_cents')->default(0);
            // Lowercase ISO code, as the gateway sends it
            $table->string('currency', 3)->default('usd');
            // pending, succeeded, failed, refunded, or partially_refunded
            $table->string('status')->index();
            $table->timestamp('paid_at')->nullable();
            // Copy of the latest gateway object
            $table->jsonb('gateway_data')->nullable();
            $table->timestamps();
            // Donations are soft-deleted, never removed
            $table->softDeletes();

            // One row per payment, among soft-deleted donations too, so a
            // payment received through several events or a re-import is
            // recorded once
            $table->unique(['gateway', 'gateway_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
