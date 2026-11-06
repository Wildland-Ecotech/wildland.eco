<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contacts' emails, phone numbers, and addresses: how they're normalized for
 * matching, and the rules the database keeps (one current address, one
 * primary detail of each type).
 */
class ContactDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_are_normalized_for_matching(): void
    {
        $this->assertSame('jamie@example.com', ContactDetail::normalize(ContactDetail::TYPE_EMAIL, ' Jamie@Example.COM '));
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '(970) 555-0123'));
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '+1 970.555.0123'));
        $this->assertSame('123 example st fort collins co 80521', ContactDetail::normalize(ContactDetail::TYPE_ADDRESS, ' 123 Example St.,  Fort Collins, CO 80521 '));
    }

    public function test_details_without_anything_to_match_normalize_to_null(): void
    {
        // No area code, or too many digits for a US number
        $this->assertNull(ContactDetail::normalize(ContactDetail::TYPE_PHONE, '555-0123'));
        $this->assertNull(ContactDetail::normalize(ContactDetail::TYPE_PHONE, '+44 20 7946 0958'));
        $this->assertNull(ContactDetail::normalize(ContactDetail::TYPE_EMAIL, '  '));
        $this->assertNull(ContactDetail::normalize(ContactDetail::TYPE_ADDRESS, null));
    }

    public function test_saving_sets_the_normalized_value(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $detail = $contact->details()->create(['type' => ContactDetail::TYPE_PHONE, 'value' => ' (970) 555-0123 ']);

        $this->assertSame('(970) 555-0123', $detail->value);
        $this->assertSame('9705550123', $detail->fresh()->normalized);
    }

    public function test_moving_keeps_the_previous_address_as_removed(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $old = $contact->moveTo('123 Example St, Fort Collins, CO 80521');
        $new = $contact->moveTo('456 Sample Ave, Fort Collins, CO 80524');

        $this->assertSame($new->id, $contact->address()->id);
        $this->assertTrue($old->fresh()->isRemoved());
        $this->assertFalse($old->fresh()->is_primary);
        $this->assertSame($old->id, $contact->findDetail(ContactDetail::TYPE_ADDRESS, '123 example st fort collins co 80521')->id);

        // Moving back puts the earlier address back in use, without a copy
        $this->assertSame($old->id, $contact->moveTo('123 Example St., Fort Collins, CO 80521')->id);
        $this->assertSame(2, $contact->details()->count());
        $this->assertTrue($new->fresh()->isRemoved());
    }

    public function test_using_an_existing_detail_as_primary_makes_no_copy(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $first = $contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $second = $contact->usePrimary(ContactDetail::TYPE_PHONE, '970-555-0199');

        $this->assertSame($first->id, $contact->usePrimary(ContactDetail::TYPE_PHONE, '9705550123')->id);
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_primary);
        $this->assertSame(2, $contact->details()->count());
    }

    public function test_the_database_allows_one_current_address(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $contact->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->expectException(QueryException::class);

        $contact->details()->create(['type' => ContactDetail::TYPE_ADDRESS, 'value' => '456 Sample Ave, Fort Collins, CO 80524']);
    }

    public function test_the_database_allows_one_current_primary_of_each_type(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $contact->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');
        // A primary phone number alongside the primary email is fine
        $contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');

        $this->expectException(QueryException::class);

        $contact->details()->make(['type' => ContactDetail::TYPE_EMAIL, 'value' => 'other@example.com'])
            ->forceFill(['is_primary' => true])
            ->save();
    }
}
