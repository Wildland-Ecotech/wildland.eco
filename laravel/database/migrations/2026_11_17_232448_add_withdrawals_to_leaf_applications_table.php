<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawn applications: real applications closed without a pickup or
 * drop-off, such as replaced applications or leaves taken elsewhere. They
 * stay listed, unlike test and spam applications, with why, who withdrew
 * them, and when.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            // Why: one of LeafApplication::WITHDRAWN_REASONS
            $table->string('withdrawn_reason', 30)->nullable();
            $table->text('withdrawn_note')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        // A reason and time on withdrawn applications only
        DB::statement("ALTER TABLE leaf_applications ADD CONSTRAINT leaf_applications_withdrawn_details CHECK ((status = 'withdrawn') = (withdrawn_reason IS NOT NULL AND withdrawn_at IS NOT NULL))");
    }

    /**
     * Reverse the migrations. Withdrawn applications go back to pending.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE leaf_applications DROP CONSTRAINT leaf_applications_withdrawn_details');
        DB::table('leaf_applications')->where('status', 'withdrawn')->update(['status' => 'pending']);

        Schema::table('leaf_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['withdrawn_reason', 'withdrawn_note', 'withdrawn_at']);
        });
    }
};
