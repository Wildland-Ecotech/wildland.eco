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
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Travels in gateway metadata. Unique among soft-deleted
            // designations too, so a slug always means the same fund
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // Donations to restricted designations can only be spent on them
            $table->boolean('is_restricted')->default(false);
            // For the future donate-page selector
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // Designations are soft-deleted, never removed
            $table->softDeletes();
        });

        // Every donation goes to the General Fund unless it names another
        // designation. Created here rather than in a seeder, since deploys
        // run migrations but never seeders.
        DB::table('designations')->insert([
            'name' => 'General Fund',
            'slug' => 'general-fund',
            'description' => null,
            'is_restricted' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
