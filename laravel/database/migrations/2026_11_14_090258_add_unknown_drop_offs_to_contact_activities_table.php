<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_activities', function (Blueprint $table) {
            // Null for drop-offs whose donor isn't known yet, until an admin
            // assigns the bags to a contact
            $table->foreignId('contact_id')->nullable()->change();
            // The brand of bags, as typed, for any pickup or drop-off
            $table->string('bag_brand', 100)->nullable();
            // For drop-offs: the drop-off site (a contact) the bags were left
            // at. Required for drop-offs with no contact
            $table->foreignId('drop_off_site_id')->nullable()->index()->constrained('contacts')->restrictOnDelete();
            // For entries split from a drop-off with no contact: the entry
            // the bags came from
            $table->foreignId('split_from_id')->nullable()->index()->constrained('contact_activities')->restrictOnDelete();
            // When and by which admin the bags were assigned to a contact
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        // Entries with no contact are drop-offs at a drop-off site, with no
        // application
        DB::statement("ALTER TABLE contact_activities ADD CONSTRAINT contact_activities_unknown_only_drop_offs CHECK (contact_id IS NOT NULL OR (type = 'drop_off' AND leaf_application_id IS NULL AND drop_off_site_id IS NOT NULL))");

        // Only drop-offs have a drop-off site
        DB::statement("ALTER TABLE contact_activities ADD CONSTRAINT contact_activities_site_only_on_drop_offs CHECK (drop_off_site_id IS NULL OR type = 'drop_off')");
    }

    /**
     * Reverse the migrations. Fails while drop-offs with no contact exist,
     * since contact_id becomes required again.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE contact_activities DROP CONSTRAINT contact_activities_site_only_on_drop_offs');
        DB::statement('ALTER TABLE contact_activities DROP CONSTRAINT contact_activities_unknown_only_drop_offs');

        Schema::table('contact_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropConstrainedForeignId('split_from_id');
            $table->dropConstrainedForeignId('drop_off_site_id');
            $table->dropColumn(['bag_brand', 'assigned_at']);
            $table->foreignId('contact_id')->nullable(false)->change();
        });
    }
};
