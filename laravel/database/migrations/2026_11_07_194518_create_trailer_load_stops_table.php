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
        // A trailer load's stops: the pickup applications, in the order of
        // the admin's route
        Schema::create('trailer_load_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trailer_load_id')->constrained()->restrictOnDelete();
            $table->foreignId('leaf_application_id')->index()->constrained()->restrictOnDelete();
            // Its place in the route, from 1
            $table->integer('position');
            // The pickup logged for it when the load was marked done
            $table->foreignId('contact_activity_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['trailer_load_id', 'leaf_application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trailer_load_stops');
    }
};
