<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets admins mark a leaf application that isn't a real request as a test
 * or spam, recording who did, when, and why. A marked application is also
 * soft-deleted, so it leaves every list.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            // How an admin marked the application: test or spam; null for a
            // real one
            $table->string('flagged_as', 20)->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('flag_note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropColumn(['flagged_as', 'flagged_at', 'flag_note']);
        });
    }
};
