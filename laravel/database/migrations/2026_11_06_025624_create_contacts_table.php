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
        // People we keep in touch with, starting with those who apply to
        // donate leaves. Their emails, phone numbers, and addresses are in
        // contact_details. Holds personal details: never copy rows into the
        // repository, issues, or chat.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Standing information, such as "prefers texts after 5pm"
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
