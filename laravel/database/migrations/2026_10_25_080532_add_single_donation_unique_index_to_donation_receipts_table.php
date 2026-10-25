<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // At most one receipt per donation, including soft-deleted receipts,
        // so no bug can ever receipt a donation twice. Year-end summaries
        // have no donation and aren't affected. A partial index, which the
        // schema builder can't express.
        DB::statement("CREATE UNIQUE INDEX donation_receipts_single_donation_unique ON donation_receipts (donation_id) WHERE type = 'single'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS donation_receipts_single_donation_unique');
    }
};
