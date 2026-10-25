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
        Schema::table('leaf_applications', function (Blueprint $table) {
            // Applications are soft-deleted, never removed
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
