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
        // Trailer loads of leaf pickups, planned on the admin Map page
        Schema::create('trailer_loads', function (Blueprint $table) {
            $table->id();
            // The day it's planned for, a Fort Collins date
            $table->date('planned_on')->index();
            // How many bags the trailer holds for this load
            $table->integer('capacity');
            // planned, done, or canceled
            $table->string('status', 20)->default('planned')->index();
            // The admin who saved it
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            // When it was marked done or canceled, and by which admin
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trailer_loads');
    }
};
