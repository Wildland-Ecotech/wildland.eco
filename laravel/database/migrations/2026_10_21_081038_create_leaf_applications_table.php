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
        Schema::create('leaf_applications', function (Blueprint $table) {
            $table->id();
            // drop_off or pickup
            $table->string('type');
            $table->string('name');
            // Stored lowercase
            $table->string('email');
            $table->string('phone');
            // Where the leaves are coming from
            $table->string('address', 500);
            // The donor's estimate; many don't know the exact count in advance
            $table->integer('estimated_bag_count');
            // Whether the bags are ready now; if not, ready_date is when they
            // will be, the soonest they can be picked up or dropped off
            $table->boolean('ready_now');
            $table->date('ready_date')->nullable();
            $table->text('referral_source')->nullable();
            $table->text('questions')->nullable();
            $table->timestamp('terms_accepted_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaf_applications');
    }
};
