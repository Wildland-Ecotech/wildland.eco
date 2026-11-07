<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Services\ContactLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Linking leaf applications to contacts (ContactLinker): to the contact with
 * the same name and email or phone number, or a new contact, flagging
 * anything unclear for review instead of guessing.
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

    public function test_a_different_name_with_the_same_phone_number_is_a_new_contact_flagged(): void
    {
        $this->linker->link($this->application());
        $other = $this->application(['name' => 'Alex Rivera', 'email' => 'alex@example.com']);

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($other));

        $other->refresh();
        $first = Contact::where('name', 'Jamie Rivera')->sole();
        $second = Contact::where('name', 'Alex Rivera')->sole();
        $this->assertSame($second->id, $other->contact_id);
        $this->assertSame([LeafApplication::REVIEW_SHARED_PHONE], $other->contact_review);
        $this->assertTrue($other->needsContactReview());
        $this->assertSame([$other->id], LeafApplication::needingContactReview()->pluck('id')->all());
        // Both have the number, and the first contact is unchanged
        $this->assertSame('9705550123', $second->primary(ContactDetail::TYPE_PHONE)->normalized);
        $this->assertSame([['jamie@example.com', true]], $this->current($first, ContactDetail::TYPE_EMAIL));
    }

    public function test_a_different_name_with_the_same_email_is_a_new_contact_flagged(): void
    {
        $this->linker->link($this->application());
        $other = $this->application(['name' => 'Jim Rivera', 'phone' => '970-555-0199']);

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($other));
        $this->assertSame([LeafApplication::REVIEW_SHARED_EMAIL], $other->fresh()->contact_review);
        $this->assertSame(2, Contact::count());
    }

    public function test_a_different_name_sharing_both_is_a_new_contact_with_both_flagged(): void
    {
        $this->linker->link($this->application());
        $other = $this->application(['name' => 'Alex Rivera', 'address' => '456 Sample Ave, Fort Collins, CO 80524']);

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($other));
        // A new contact has no other address, so only the sharing is flagged
        $this->assertSame([LeafApplication::REVIEW_SHARED_EMAIL, LeafApplication::REVIEW_SHARED_PHONE], $other->fresh()->contact_review);
        $this->assertSame('456 Sample Ave, Fort Collins, CO 80524', Contact::where('name', 'Alex Rivera')->sole()->address()->value);
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

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($later));

        $contact = Contact::sole();
        $this->assertSame($contact->id, $later->fresh()->contact_id);
        $this->assertSame([LeafApplication::REVIEW_ADDRESS], $later->fresh()->contact_review);
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $contact->address()->value);
        // The same person, so the new number is still added
        $this->assertSame([['(970) 555-0123', false], ['970-555-0199', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
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

    public function test_matching_another_contact_by_phone_links_to_the_one_with_the_same_name(): void
    {
        $this->linker->link($this->application());
        $this->linker->link($this->application(['name' => 'Alex Moreno', 'email' => 'alex@example.com', 'phone' => '970-555-0199']));
        $mixed = $this->application(['phone' => '970-555-0199']);

        $this->assertSame(ContactLinker::LINKED, $this->linker->link($mixed));

        $mixed->refresh();
        $jamie = Contact::where('name', 'Jamie Rivera')->sole();
        $this->assertSame($jamie->id, $mixed->contact_id);
        $this->assertSame([LeafApplication::REVIEW_SHARED_PHONE], $mixed->contact_review);
        $this->assertSame('970-555-0199', $jamie->primary(ContactDetail::TYPE_PHONE)->value);
    }

    public function test_two_contacts_with_the_same_name_link_to_neither(): void
    {
        Contact::create(['name' => 'Jamie Rivera'])->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');
        Contact::create(['name' => 'Jamie Rivera'])->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $application = $this->application();

        $this->assertSame(ContactLinker::CONFLICT, $this->linker->link($application));

        $application->refresh();
        $this->assertNull($application->contact_id);
        $this->assertSame([LeafApplication::REVIEW_CONFLICT], $application->contact_review);
        $this->assertTrue($application->needsContactReview());
        $this->assertSame(2, Contact::count());
        $this->assertSame(2, ContactDetail::count());
    }

    public function test_deleted_contacts_are_not_matched(): void
    {
        $this->linker->link($this->application());
        Contact::sole()->delete();

        $application = $this->application();

        $this->assertSame(ContactLinker::CREATED, $this->linker->link($application));
        $this->assertSame(1, Contact::count());
        $this->assertSame(2, Contact::withTrashed()->count());
        // Nothing shared with a deleted contact
        $this->assertNull($application->fresh()->contact_review);
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
