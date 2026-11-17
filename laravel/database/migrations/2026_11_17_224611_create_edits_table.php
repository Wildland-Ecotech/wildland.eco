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
        // The audit trail for any record: an append-only log of edits,
        // deletions, and other changes by admins, never changed or deleted
        Schema::create('edits', function (Blueprint $table) {
            $table->id();
            // The record changed: a short type name from the morph map
            // (AppServiceProvider), such as contact_activity, and its ID
            $table->morphs('editable');
            // What happened, such as edited or deleted (Edit::ACTIONS)
            $table->string('action', 20);
            // Each changed field's stored value before and after:
            // {"bag_count": {"from": 24, "to": 22}}
            $table->jsonb('fields');
            $table->foreignId('edited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edits');
    }
};
