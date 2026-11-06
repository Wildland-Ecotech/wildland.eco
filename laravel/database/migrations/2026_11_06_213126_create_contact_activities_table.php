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
        // A contact's timeline entries, logged by admins: texts, calls,
        // visits, notes, pickups, and drop-offs
        Schema::create('contact_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            // text, call, visit, note, pickup, or drop_off
            $table->string('type', 20);
            // For texts and calls: outgoing (from us) or incoming (to us)
            $table->string('direction', 20)->nullable();
            // When it happened, which may be before it was logged
            $table->timestamp('happened_at');
            // What was said, or the note
            $table->text('body')->nullable();
            // For pickups and drop-offs
            $table->integer('bag_count')->nullable();
            // The application a pickup or drop-off was for, which it marks
            // complete
            $table->foreignId('leaf_application_id')->nullable()->index()->constrained()->restrictOnDelete();
            // The admin who logged it
            $table->foreignId('logged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'happened_at']);
        });

        Schema::table('leaf_applications', function (Blueprint $table) {
            // pending until a pickup or drop-off is logged for it, then
            // complete
            $table->string('status', 20)->default('pending')->index();
            // When an admin said to check back, such as when the donor asked
            // us to text them next week
            $table->date('follow_up_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            $table->dropColumn(['status', 'follow_up_on']);
        });

        Schema::dropIfExists('contact_activities');
    }
};
