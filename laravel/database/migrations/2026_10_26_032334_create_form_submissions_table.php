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
        // Every submission of the donate and leaf application forms, recorded
        // the moment it arrives, so a visitor who hits an error can still be
        // reached. Kept permanently.
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            // Shown to the visitor in error messages, to find their submission
            $table->string('reference', 6)->unique();
            $table->string('form')->index();
            // As typed, even if invalid; email is trimmed and lowercased
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            // The rest of the form as submitted, without security tokens
            $table->jsonb('data')->nullable();
            $table->string('outcome')->default('received')->index();
            // The messages the visitor was shown, by field
            $table->jsonb('errors')->nullable();
            $table->foreignId('checkout_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('leaf_application_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
