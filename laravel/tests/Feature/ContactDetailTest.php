<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Contacts' emails, phone numbers, and addresses: how they're normalized for
 * matching, the rules the database keeps (one current address, one primary
 * detail of each type), and addresses' map locations.
 */
class ContactDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_are_normalized_for_matching(): void
    {
        $this->assertSame('jamie@example.com', ContactDetail::normalize(ContactDetail::TYPE_EMAIL, ' Jamie@Example.COM '));
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '(970) 555-0123'));
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '+1 970.555.0123'));
        // An extension is left out, so the number matches with or without it
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '970-555-0123 ext. 4'));
        $this->assertSame('9705550123', ContactDetail::normalize(ContactDetail::TYPE_PHONE, '(970) 555-0123x12'));
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

    public function test_the_forms_take_us_phone_numbers_with_any_extension(): void
    {
        foreach (['(970) 555-0123', '970.555.0123', '9705550123', '+1 970 555 0123', '1-970-555-0123', '970-555-0123 x4', '970-555-0123 ext. 12', '(970) 555-0123 Extension 123456'] as $phone) {
            $this->assertMatchesRegularExpression(ContactDetail::PHONE_PATTERN, $phone);
        }

        foreach (['21451436735', '555-0123', '+44 20 7946 0958', '970-555-01234', '970-555-0123 ext', '970-555-0123 x1234567', '970-555-0123 #4', 'call me'] as $phone) {
            $this->assertDoesNotMatchRegularExpression(ContactDetail::PHONE_PATTERN, $phone);
        }
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

    public function test_locating_an_address_stores_its_coordinates(): void
    {
        $this->freezeSecond();
        $admin = User::factory()->admin()->create();
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $address = $contact->moveTo('123 Example St, Fort Collins, CO 80521');
        $this->assertNull($address->location);

        $address->locate(40.5898512, -105.0819134, $admin);

        // The same before and after reading it back from the database
        foreach ([$address, $address->fresh()] as $detail) {
            $this->assertEqualsWithDelta(40.5898512, $detail->location['latitude'], 1e-9);
            $this->assertEqualsWithDelta(-105.0819134, $detail->location['longitude'], 1e-9);
        }

        $address->refresh();
        $this->assertSame($admin->id, $address->located_by);
        $this->assertTrue(now()->eq($address->located_at));
        // PostGIS holds a point, longitude first
        $this->assertSame('POINT(-105.0819134 40.5898512)', DB::scalar('SELECT ST_AsText(location) FROM contact_details WHERE id = ?', [$address->id]));

        // A new address has no location until it's located
        $this->assertNull($contact->moveTo('456 Sample Ave, Fort Collins, CO 80524')->fresh()->location);
    }

    public function test_only_addresses_have_a_location(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $phone = $contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');

        $this->expectException(QueryException::class);

        $phone->locate(40.5898512, -105.0819134, User::factory()->create());
    }

    public function test_a_location_must_be_on_the_globe(): void
    {
        $address = Contact::create(['name' => 'Jamie Rivera'])->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->expectException(InvalidArgumentException::class);

        $address->locate(105.08191, 40.58985, User::factory()->create());
    }
}
