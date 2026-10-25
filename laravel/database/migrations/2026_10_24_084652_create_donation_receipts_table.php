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
        Schema::create('donation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            // Set for receipts for a single donation
            $table->foreignId('donation_id')->nullable()->index()->constrained()->restrictOnDelete();
            // Unique among soft-deleted receipts too, so a number is never reused
            $table->string('receipt_number')->unique();
            // single or annual_summary
            $table->string('type');
            $table->integer('tax_year');
            $table->integer('amount_cents');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            // Receipts are soft-deleted, never removed
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donation_receipts');
    }
};
