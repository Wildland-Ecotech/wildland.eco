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
 * The Contact section of an admin application page (/admin/leaves/{id}):
 * who the application is linked to, why it needs review, marking it
 * reviewed, and moving or linking it to another contact or a new one.
 */
class AdminApplicationContactTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-05 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Admin']);
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

    public function test_only_signed_in_admins_can_settle_an_application(): void
    {
        $application = $this->apply();
        $requests = ["/admin/leaves/{$application->id}/review", "/admin/leaves/{$application->id}/contact"];

        // Every request as a guest first, since signing in lasts for the rest of the test
        foreach ($requests as $url) {
            $this->post($url)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($requests as $url) {
            $this->actingAs($donor)->post($url)->assertForbidden();
        }
    }

    public function test_shows_the_contact_an_application_is_linked_to(): void
    {
        $application = $this->apply();

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertOk()
            ->assertSee('Linked to <a href="' . route('admin.contacts.show', $application->contact_id) . '">Jamie Rivera</a>.', false)
            ->assertDontSee('Needs review.')
            ->assertDontSee('Mark Reviewed')
            ->assertSee('Move this application to')
            // Nobody else shares the details, so only a new contact is offered
            ->assertSee('<option value="new" >A new contact</option>', false);
    }

    public function test_explains_a_shared_phone_number_and_offers_the_other_contact(): void
    {
        $this->apply();
        $jamie = Contact::sole();
        $application = $this->apply(['name' => 'Alex Rivera', 'email' => 'alex@example.com']);

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertSee('Linked to <a href="' . route('admin.contacts.show', $application->contact_id) . '">Alex Rivera</a>.', false)
            ->assertSee('Phone number also on another contact: <a href="' . route('admin.contacts.show', $jamie->id) . '">Jamie Rivera</a>.', false)
            ->assertSee('Mark it reviewed if nothing needs to change, or move it to the right contact below.')
            ->assertSee('Mark Reviewed')
            ->assertSee('<option value="' . $jamie->id . '" >Jamie Rivera · 970-555-0123</option>', false);
    }

    public function test_marks_an_application_reviewed(): void
    {
        $this->apply();
        $application = $this->apply(['name' => 'Alex Rivera', 'email' => 'alex@example.com']);

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$application->id}/review")
            ->assertRedirect(route('admin.leaves.show', $application->id))
            ->assertSessionHas('status', "Marked application {$application->id} reviewed.");

        $application->refresh();
        $this->assertFalse($application->needsContactReview());
        $this->assertSame($this->admin->id, $application->contact_reviewed_by);

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertDontSee('Needs review.')
            ->assertSee('Reviewed by Sam Admin on Nov 5, 7:30 PM.');
    }

    public function test_moves_an_application_to_the_contact_it_belongs_with(): void
    {
        $this->apply();
        $jamie = Contact::where('name', 'Jamie Rivera')->sole();
        $application = $this->apply(['name' => 'Jamie R.', 'email' => 'jr@example.com', 'address' => '456 Sample Ave, Fort Collins, CO 80524']);
        $made = $application->contact;

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$application->id}/contact", ['contact' => (string) $jamie->id])
            ->assertRedirect(route('admin.leaves.show', $application->id))
            ->assertSessionHas('status', "Moved application {$application->id} to Jamie Rivera.");

        $application->refresh();
        $this->assertSame($jamie->id, $application->contact_id);
        $this->assertFalse($application->needsContactReview());
        // The application's email joins Jamie's, not as the main one, and
        // Jamie keeps their address
        $this->assertSame('jamie@example.com', $jamie->primary(ContactDetail::TYPE_EMAIL)->value);
        $this->assertNotNull($jamie->findDetail(ContactDetail::TYPE_EMAIL, 'jr@example.com'));
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $jamie->address()->value);
        // The contact made for it stays, for an admin to merge or leave
        $this->assertNotNull($made->fresh());
    }

    public function test_moves_an_application_to_a_new_contact(): void
    {
        $application = $this->apply();
        $jamie = $application->contact;

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$application->id}/contact", ['contact' => 'new'])
            ->assertSessionHas('status', "Made a new contact, Jamie Rivera, for application {$application->id}.");

        $application->refresh();
        $this->assertNotSame($jamie->id, $application->contact_id);
        $this->assertSame(2, Contact::count());
        $this->assertSame('9705550123', $application->contact->primary(ContactDetail::TYPE_PHONE)->normalized);
        $this->assertSame('123 Example St, Fort Collins, CO 80521', $application->contact->address()->value);
    }

    public function test_links_an_application_that_matches_two_contacts(): void
    {
        $byEmail = Contact::create(['name' => 'Jamie Rivera']);
        $byEmail->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');
        $byPhone = Contact::create(['name' => 'Jamie Rivera']);
        $byPhone->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $application = $this->apply();

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertSee('Not linked to a contact.')
            ->assertSee('Matches more than one contact with this name: <a href="' . route('admin.contacts.show', $byEmail->id) . '">Jamie Rivera</a> (by email), <a href="' . route('admin.contacts.show', $byPhone->id) . '">Jamie Rivera</a> (by phone number).', false)
            ->assertSee('Link it to the right contact below, or to a new one.')
            ->assertSee('Link this application to')
            // Linking settles it, so there's no Mark Reviewed
            ->assertDontSee('Mark Reviewed');

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$application->id}/contact", ['contact' => (string) $byPhone->id])
            ->assertSessionHas('status', "Linked application {$application->id} to Jamie Rivera.");

        $application->refresh();
        $this->assertSame($byPhone->id, $application->contact_id);
        $this->assertFalse($application->needsContactReview());
        $this->assertSame('jamie@example.com', $byPhone->primary(ContactDetail::TYPE_EMAIL)->value);
    }

    public function test_a_contact_must_be_chosen_from_the_list(): void
    {
        $application = $this->apply();
        $deleted = Contact::create(['name' => 'Gone Contact']);
        $deleted->delete();

        // Pairs rather than keys, since PHP turns numeric string keys into
        // integers, and a form always sends text
        $cases = [
            ['', 'Choose a contact from the list, or a new contact.'],
            ['someone', 'Choose a contact from the list, or a new contact.'],
            [(string) $application->contact_id, 'The application is already linked to that contact. Choose another, or a new contact.'],
            [(string) $deleted->id, 'That contact no longer exists. Choose another, or a new contact.'],
        ];

        foreach ($cases as [$contact, $message]) {
            $this->actingAs($this->admin)
                ->post("/admin/leaves/{$application->id}/contact", ['contact' => $contact])
                ->assertSessionHasErrors(['contact' => $message]);
        }

        $this->assertSame(1, Contact::count());
    }

    public function test_a_deleted_application_shows_its_contact_without_the_forms(): void
    {
        $application = $this->apply();
        $application->delete();

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertSee('Linked to')
            ->assertDontSee('Move this application to');

        $this->actingAs($this->admin)->post("/admin/leaves/{$application->id}/contact", ['contact' => 'new'])->assertNotFound();
    }
}
