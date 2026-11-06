<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use App\Services\ContactLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Merging one contact into another (ContactMerger), from a contact's page:
 * the contacts sharing their details, and the Merge button.
 */
class ContactMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-05 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    /**
     * A saved application, linked by ContactLinker like one from the form.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function apply(array $overrides = []): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            ...$overrides,
        ]);

        app(ContactLinker::class)->link($application);

        return $application->fresh();
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

    public function test_only_signed_in_admins_can_merge(): void
    {
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $other = Contact::create(['name' => 'Jamie R.']);
        $url = "/admin/contacts/{$jamie->id}/merge/{$other->id}";

        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->withPassword()->create())->post($url)->assertForbidden();
        $this->assertNull($other->fresh()->deleted_at);
    }

    public function test_a_contact_page_lists_contacts_sharing_a_phone_number_or_email(): void
    {
        $jamie = $this->apply()->contact;
        $alex = $this->apply(['name' => 'Alex Rivera', 'email' => 'alex@example.com', 'phone' => '970.555.0123'])->contact;
        Contact::create(['name' => 'Sam Lee'])->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0144');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$jamie->id}")
            ->assertSeeInOrder(['Shares Details With', 'Alex Rivera', '· (970) 555-0123', 'Merge Into Jamie Rivera', 'Applications'], false)
            ->assertSee('action="' . route('admin.contacts.merge', [$jamie->id, $alex->id]) . '"', false)
            ->assertDontSee('Sam Lee');
    }

    public function test_nothing_shared_shows_no_section(): void
    {
        $jamie = $this->apply()->contact;

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$jamie->id}")
            ->assertDontSee('Shares Details With');
    }

    public function test_merging_moves_applications_details_and_notes(): void
    {
        $jamie = $this->apply()->contact;
        $jamie->update(['notes' => 'Prefers texts']);
        $flagged = $this->apply(['name' => 'Jamie R.', 'email' => 'jr@example.com', 'address' => '456 Sample Ave, Fort Collins, CO 80524']);
        $other = $flagged->contact;
        $other->update(['notes' => 'Has a trailer']);

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$jamie->id}/merge/{$other->id}")
            ->assertRedirect(route('admin.contacts.show', $jamie->id))
            ->assertSessionHas('status', 'Merged Jamie R. into Jamie Rivera, with 1 application.');

        $jamie->refresh();
        $flagged->refresh();
        $this->assertSame($jamie->id, $flagged->contact_id);
        $this->assertSame("Prefers texts\n\nHas a trailer", $jamie->notes);
        // Jamie's main email stays; the other one joins, not as the main one
        $this->assertSame([['jamie@example.com', true], ['jr@example.com', false]], $this->current($jamie, ContactDetail::TYPE_EMAIL));
        // The shared number isn't doubled
        $this->assertSame([['(970) 555-0123', true]], $this->current($jamie, ContactDetail::TYPE_PHONE));
        // Jamie keeps their address; the other one is kept as previous
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $jamie->address()->value);
        $this->assertTrue($jamie->findDetail(ContactDetail::TYPE_ADDRESS, '456 Sample Ave, Fort Collins, CO 80524')->isRemoved());
        // The merged contact is deleted, pointing to Jamie
        $other = Contact::withTrashed()->find($other->id);
        $this->assertTrue($other->trashed());
        $this->assertSame($jamie->id, $other->merged_into_id);
        // Sharing the number was the only reason to look, and nobody else has it now
        $this->assertFalse($flagged->needsContactReview());
        $this->assertSame($this->admin->id, $flagged->contact_reviewed_by);
    }

    public function test_reviews_for_other_reasons_stay_open(): void
    {
        $jamie = $this->apply()->contact;
        $moved = $this->apply(['address' => '456 Sample Ave, Fort Collins, CO 80524', 'phone' => '970-555-0199']);
        $other = $this->apply(['name' => 'Jamie R.', 'phone' => '970-555-0177'])->contact;

        $this->actingAs($this->admin)->post("/admin/contacts/{$jamie->id}/merge/{$other->id}");

        // Flagged for a different address, which merging doesn't settle
        $this->assertTrue($moved->fresh()->needsContactReview());
    }

    public function test_a_contact_without_an_address_or_main_number_gets_the_merged_ones(): void
    {
        $kept = Contact::create(['name' => 'Jamie Rivera']);
        $kept->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');
        $other = $this->apply(['name' => 'Jamie R.'])->contact;

        $this->actingAs($this->admin)->post("/admin/contacts/{$kept->id}/merge/{$other->id}");

        $this->assertSame([['(970) 555-0123', true]], $this->current($kept, ContactDetail::TYPE_PHONE));
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $kept->address()->value);
    }

    public function test_a_previous_detail_in_use_by_the_merged_contact_is_put_back(): void
    {
        $kept = $this->apply()->contact;
        $kept->usePrimary(ContactDetail::TYPE_PHONE, '970-555-0199');
        $kept->removeDetail($kept->findDetail(ContactDetail::TYPE_PHONE, '9705550123'));
        $other = Contact::create(['name' => 'Jamie R.']);
        $other->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');

        $this->actingAs($this->admin)->post("/admin/contacts/{$kept->id}/merge/{$other->id}");

        $this->assertSame([['(970) 555-0123', false], ['970-555-0199', true]], $this->current($kept, ContactDetail::TYPE_PHONE));
    }

    public function test_a_merged_contacts_page_goes_to_the_contact_it_became_part_of(): void
    {
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $middle = Contact::create(['name' => 'Jamie R.']);
        $first = Contact::create(['name' => 'J. Rivera']);

        $this->actingAs($this->admin)->post("/admin/contacts/{$middle->id}/merge/{$first->id}");
        $this->actingAs($this->admin)->post("/admin/contacts/{$jamie->id}/merge/{$middle->id}");

        // Following a merge of a merge
        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$first->id}")
            ->assertRedirect(route('admin.contacts.show', $jamie->id))
            ->assertSessionHas('status', 'That contact was merged into Jamie Rivera.');
    }

    public function test_a_deleted_contact_that_wasnt_merged_is_not_found(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $contact->delete();

        $this->actingAs($this->admin)->get("/admin/contacts/{$contact->id}")->assertNotFound();
    }

    public function test_a_contact_cannot_be_merged_into_itself_or_a_deleted_one(): void
    {
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $deleted = Contact::create(['name' => 'Jamie R.']);
        $deleted->delete();

        $this->actingAs($this->admin)->post("/admin/contacts/{$jamie->id}/merge/{$jamie->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/contacts/{$jamie->id}/merge/{$deleted->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/contacts/{$deleted->id}/merge/{$jamie->id}")->assertNotFound();
        $this->assertNull($jamie->fresh()->deleted_at);
    }
}
