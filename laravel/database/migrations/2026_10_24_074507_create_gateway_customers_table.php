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
        Schema::create('gateway_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            // The payment gateway (stripe) and its ID for the customer
            $table->string('gateway');
            $table->string('gateway_customer_id');
            $table->timestamps();
            // Customers are soft-deleted, never removed
            $table->softDeletes();

            // Unique among soft-deleted customers too, so a re-import
            // never creates a duplicate
            $table->unique(['gateway', 'gateway_customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_customers');
    }
};
