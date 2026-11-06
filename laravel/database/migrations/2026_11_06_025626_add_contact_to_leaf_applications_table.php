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
            // The person who applied; null until linked, or while the
            // application waits for review because its email and phone
            // number belong to different contacts
            $table->foreignId('contact_id')->nullable()->index()->constrained()->restrictOnDelete();
            // Why linking needs an admin to look: name, address, or
            // conflict. Null when nothing needs a look.
            $table->jsonb('contact_review')->nullable();
            $table->timestamp('contact_reviewed_at')->nullable();
            $table->foreignId('contact_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaf_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_reviewed_by');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn(['contact_review', 'contact_reviewed_at']);
        });
    }
};
