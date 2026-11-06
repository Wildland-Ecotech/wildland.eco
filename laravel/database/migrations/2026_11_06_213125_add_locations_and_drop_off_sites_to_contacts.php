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
        // Map locations are PostGIS points. The postgis/postgis image creates
        // the extension in a new database already; this makes sure every
        // database has it (see docs/05-ai-development-notes.md).
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::table('contacts', function (Blueprint $table) {
            // One of our drop-off sites, shown on the map with the
            // applications waiting for a pickup
            $table->boolean('is_drop_off_site')->default(false);
        });

        Schema::table('contact_details', function (Blueprint $table) {
            // An address's map location, a longitude and latitude (WGS 84).
            // Null until it's geocoded.
            $table->geography('location', 'point')->nullable();
            $table->timestamp('located_at')->nullable();
            $table->foreignId('located_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->spatialIndex('location');
        });

        // Only addresses have a location
        DB::statement("ALTER TABLE contact_details ADD CONSTRAINT contact_details_location_only_on_addresses CHECK (location IS NULL OR type = 'address')");
    }

    /**
     * Reverse the migrations. The PostGIS extension stays, since the
     * database image provides it anyway.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE contact_details DROP CONSTRAINT contact_details_location_only_on_addresses');

        Schema::table('contact_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('located_by');
            $table->dropSpatialIndex(['location']);
            $table->dropColumn(['location', 'located_at']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_drop_off_site');
        });
    }
};
