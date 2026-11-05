<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the spam mark with closing a submission as resolved, a test, or
 * spam, with an optional note. Existing spam marks become closed as spam,
 * keeping who marked them and when.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            // How an admin closed the submission: resolved, test, or spam;
            // null while it's open
            $table->string('resolution', 20)->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
        });

        DB::table('form_submissions')->whereNotNull('marked_spam_at')->update([
            'resolution' => 'spam',
            'resolved_at' => DB::raw('marked_spam_at'),
            'resolved_by' => DB::raw('marked_spam_by'),
        ]);

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marked_spam_by');
            $table->dropColumn('marked_spam_at');
        });
    }

    /**
     * Reverse the migrations. Submissions closed as resolved or a test
     * reopen, since the spam mark can't hold them.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->timestamp('marked_spam_at')->nullable()->index();
            $table->foreignId('marked_spam_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        DB::table('form_submissions')->where('resolution', 'spam')->update([
            'marked_spam_at' => DB::raw('resolved_at'),
            'marked_spam_by' => DB::raw('resolved_by'),
        ]);

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolution', 'resolved_at', 'resolution_note']);
        });
    }
};
