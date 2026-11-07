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
        // Every attempt to sign in, for spotting someone trying to get in.
        // Never the password.
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            // The email address as typed, trimmed and lowercased
            $table->string('email')->nullable()->index();
            // The admin account with that email address, when there is one
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('ip_address', 45)->index();
            $table->string('user_agent', 500)->nullable();
            // signed_in, failed, rate_limited, unverified, incomplete,
            // expired, or error
            $table->string('outcome', 20)->index();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
