<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Services\ContactLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Linking leaf applications to contacts (ContactLinker): by email or phone
 * number, creating a contact when nobody matches, and flagging anything
 * unclear for review instead of guessing.
 */
class ContactLinkingTest extends TestCase
{
    use RefreshDatabase;

    private ContactLinker $linker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linker = app(ContactLinker::class);
    }

    /**
     * A saved application, with any fields overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function application(array $overrides = []): LeafApplication
    {
        return LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * The contact's current details of a type, as [value, primary] pairs.
     *
     * @return list<array{string, bool}>
     */
    private function current(Contact $contact, string $type): array
    {
        return $contact->details()->current()->where('type', $type)->orderBy('id')->get()
            ->map(fn (ContactDetail $detail) => [$detail->value, $detail->is_primary])
            ->all();
    }

    public function test_a_new_application_creates_a_contact_with_its_details(): void
    {
        $application = $this->application();

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($application));

        $contact = Contact::sole();
        $this->assertSame('Jamie Rivera', $contact->name);
        $this->assertSame($contact->id, $application->fresh()->contact_id);
        $this->assertNull($application->fresh()->contact_review);

        $this->assertSame([['jamie@example.com', true]], $this->current($contact, ContactDetail::TYPE_EMAIL));
        $this->assertSame([['(970) 555-0123', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
        $this->assertSame('9705550123', $contact->primary(ContactDetail::TYPE_PHONE)->normalized);
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $contact->address()->value);
    }

    public function test_a_later_application_with_the_same_email_links_and_brings_its_phone_number(): void
    {
        $this->linker->link($this->application());
        $later = $this->application(['phone' => '970-555-0199']);

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($later));

        $contact = Contact::sole();
        $this->assertSame($contact->id, $later->fresh()->contact_id);
        // The newest application's number is primary; the earlier one stays
        $this->assertSame([['(970) 555-0123', false], ['970-555-0199', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
        $this->assertCount(1, $this->current($contact, ContactDetail::TYPE_EMAIL));
    }

    public function test_the_same_phone_number_written_another_way_links(): void
    {
        $this->linker->link($this->application());
        $later = $this->application(['email' => 'jamie.rivera@example.com', 'phone' => '+1 970.555.0123']);

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($later));

        $contact = Contact::sole();
        $this->assertSame([['jamie@example.com', false], ['jamie.rivera@example.com', true]], $this->current($contact, ContactDetail::TYPE_EMAIL));
        // The same number, so no second one
        $this->assertCount(1, $this->current($contact, ContactDetail::TYPE_PHONE));
    }

    public function test_a_removed_email_still_matches_and_is_put_back_in_use(): void
    {
        $this->linker->link($this->application());
        $contact = Contact::sole();
        $contact->usePrimary(ContactDetail::TYPE_EMAIL, 'new@example.com');
        $contact->findDetail(ContactDetail::TYPE_EMAIL, 'jamie@example.com')->forceFill(['removed_at' => now()])->save();

        $later = $this->application(['phone' => '']);

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($later));
        $this->assertSame($contact->id, $later->fresh()->contact_id);
        $this->assertSame('jamie@example.com', $contact->primary(ContactDetail::TYPE_EMAIL)->value);
        $this->assertSame(1, $contact->details()->where('normalized', 'jamie@example.com')->count());
    }

    public function test_a_different_name_is_linked_and_flagged_without_changing_the_contact(): void
    {
        $this->linker->link($this->application());
        $later = $this->application(['name' => 'Alex Rivera', 'email' => 'alex@example.com']);

        $this->assertSame(ContactLinker::FLAGGED, $this->linker->link($later));

        $later->refresh();
        $contact = Contact::sole();
        $this->assertSame($contact->id, $later->contact_id);
        $this->assertSame([LeafApplication::REVIEW_NAME], $later->contact_review);
        $this->assertTrue($later->needsContactReview());
        $this->assertSame([$later->id], LeafApplication::needingContactReview()->pluck('id')->all());
        // Maybe someone else at the same number, so nothing is added
        $this->assertSame([['jamie@example.com', true]], $this->current($contact, ContactDetail::TYPE_EMAIL));
        $this->assertSame('Jamie Rivera', $contact->name);
    }

    public function test_names_differing_only_in_case_and_spacing_match(): void
    {
        $this->linker->link($this->application());

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($this->application(['name' => ' jamie  RIVERA '])));
    }

    public function test_a_different_address_is_linked_and_flagged_without_moving_the_contact(): void
    {
        $this->linker->link($this->application());
        $later = $this->application(['address' => '456 Sample Ave, Fort Collins, CO 80524', 'phone' => '970-555-0199']);

        $this->assertSame(ContactLinker::FLAGGED, $this->linker->link($later));

        $contact = Contact::sole();
        $this->assertSame([LeafApplication::REVIEW_ADDRESS], $later->fresh()->contact_review);
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $contact->address()->value);
        $this->assertCount(1, $this->current($contact, ContactDetail::TYPE_PHONE));
    }

    public function test_a_different_name_and_address_are_both_flagged(): void
    {
        $this->linker->link($this->application());
        $later = $this->application(['name' => 'Alex Rivera', 'address' => '456 Sample Ave, Fort Collins, CO 80524']);

        $this->assertSame(ContactLinker::FLAGGED, $this->linker->link($later));
        $this->assertSame([LeafApplication::REVIEW_NAME, LeafApplication::REVIEW_ADDRESS], $later->fresh()->contact_review);
    }

    public function test_the_same_address_written_differently_matches(): void
    {
        $this->linker->link($this->application());

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($this->application(['address' => '123 example st.  fort collins co 80521'])));
        $this->assertCount(1, $this->current(Contact::sole(), ContactDetail::TYPE_ADDRESS));
    }

    public function test_a_contact_without_an_address_gets_the_applications(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $contact->usePrimary(ContactDetail::TYPE_PHONE, '9705550123');

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($this->application()));
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $contact->address()->value);
    }

    public function test_email_and_phone_matching_different_contacts_link_to_neither(): void
    {
        $this->linker->link($this->application());
        $this->linker->link($this->application(['name' => 'Alex Moreno', 'email' => 'alex@example.com', 'phone' => '970-555-0199']));
        $mixed = $this->application(['email' => 'jamie@example.com', 'phone' => '970-555-0199']);

        $this->assertSame(ContactLinker::CONFLICT, $this->linker->link($mixed));

        $mixed->refresh();
        $this->assertNull($mixed->contact_id);
        $this->assertSame([LeafApplication::REVIEW_CONFLICT], $mixed->contact_review);
        $this->assertTrue($mixed->needsContactReview());
        $this->assertSame(2, Contact::count());
    }

    public function test_deleted_contacts_are_not_matched(): void
    {
        $this->linker->link($this->application());
        Contact::sole()->delete();

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($this->application()));
        $this->assertSame(1, Contact::count());
        $this->assertSame(2, Contact::withTrashed()->count());
    }

    public function test_applications_already_linked_or_flagged_are_skipped(): void
    {
        $application = $this->application();
        $this->linker->link($application);

        $this->assertSame(ContactLinker::SKIPPED, $this->linker->link($application));
        $this->assertSame(1, Contact::count());
    }

    public function test_a_phone_number_without_an_area_code_is_kept_but_never_matched(): void
    {
        $this->linker->link($this->application(['phone' => '555-0123']));
        $other = $this->application(['name' => 'Alex Moreno', 'email' => 'alex@example.com', 'phone' => '555-0123']);

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($other));

        $first = Contact::orderBy('id')->first();
        $this->assertSame('555-0123', $first->primary(ContactDetail::TYPE_PHONE)->value);
        $this->assertNull($first->primary(ContactDetail::TYPE_PHONE)->normalized);
    }
}
