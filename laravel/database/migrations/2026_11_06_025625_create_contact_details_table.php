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
        // A contact's emails, phone numbers, and address, several per
        // contact. A detail that changes is marked removed rather than
        // deleted, so matching still finds a previous number or address.
        Schema::create('contact_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->index()->constrained()->restrictOnDelete();
            // email, phone, or address
            $table->string('type', 20);
            // As entered
            $table->string('value', 500);
            // For matching: a lowercase email, a US phone number as 10
            // digits, or an address lowercased without punctuation. Null
            // when there's nothing to match on, such as a phone number
            // without an area code.
            $table->string('normalized', 500)->nullable();
            // Such as "mobile"
            $table->string('label', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'normalized']);
        });

        // At most one current address per contact, and at most one primary
        // detail of each type
        DB::statement("CREATE UNIQUE INDEX contact_details_one_current_address ON contact_details (contact_id) WHERE type = 'address' AND removed_at IS NULL");
        DB::statement('CREATE UNIQUE INDEX contact_details_one_primary ON contact_details (contact_id, type) WHERE is_primary AND removed_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_details');
    }
};
