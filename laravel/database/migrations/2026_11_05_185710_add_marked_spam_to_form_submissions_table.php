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
        Schema::table('form_submissions', function (Blueprint $table) {
            // When an admin marked the submission as spam, such as a bot
            // filling in a form, and who did; both null when it isn't spam
            $table->timestamp('marked_spam_at')->nullable()->index();
            $table->foreignId('marked_spam_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marked_spam_by');
            $table->dropColumn('marked_spam_at');
        });
    }
};
