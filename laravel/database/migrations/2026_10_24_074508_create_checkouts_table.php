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
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            // Public identifier, sent to the gateway as metadata
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->index()->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            // Lowercase ISO code, as the gateway sends it
            $table->string('currency', 3)->default('usd');
            // one_time, monthly, or annual
            $table->string('frequency');
            // started, completed, or expired
            $table->string('status')->default('started')->index();
            $table->string('gateway');
            // Set once the gateway session is created
            $table->string('gateway_session_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            // Checkouts are soft-deleted, never removed
            $table->softDeletes();

            $table->unique(['gateway', 'gateway_session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
