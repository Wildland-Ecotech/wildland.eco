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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // Stored lowercase. Unique among soft-deleted users too, so a
            // deleted account keeps its address reserved
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Donors can always sign in with an email link
            $table->string('password')->nullable();
            // donor or admin
            $table->string('role')->default('donor');
            $table->rememberToken();
            $table->timestamps();
            // Users are soft-deleted, never removed
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
