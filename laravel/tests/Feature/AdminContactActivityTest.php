<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A contact's timeline on their page, and logging texts, calls, visits,
 * notes, pickups, and drop-offs on it (/admin/contacts/{id}/activities/new).
 */
class AdminContactActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-06 15:05', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Rachel']);
        $this->contact = Contact::create(['name' => 'Jamie Rivera']);
        $this->contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
    }

    /**
     * A pending pickup application of the contact's, made $daysAgo.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(int $daysAgo = 2, array $attributes = [], ?Contact $contact = null): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now()->subDays($daysAgo),
            'contact_id' => ($contact ?? $this->contact)->id,
            'created_at' => now()->subDays($daysAgo),
            ...$attributes,
        ])->save();

        return $application;
    }

    /**
     * Log an entry for the contact as the admin.
     *
     * @param  array<string, mixed>  $input
     */
    private function log(array $input)
    {
        return $this->actingAs($this->admin)->post("/admin/contacts/{$this->contact->id}/activities", [
            'type' => ContactActivity::TYPE_TEXT,
            'direction' => ContactActivity::DIRECTION_OUTGOING,
            'happened_at' => '2026-11-06T14:30',
            ...$input,
        ]);
    }

    public function test_only_signed_in_admins_can_log(): void
    {
        $url = "/admin/contacts/{$this->contact->id}/activities";

        $this->get("{$url}/new")->assertRedirect(route('login'));
        $this->post($url, ['type' => 'note', 'happened_at' => '2026-11-06T14:30'])->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->get("{$url}/new")->assertForbidden();
        $this->actingAs($donor)->post($url, ['type' => 'note', 'happened_at' => '2026-11-06T14:30'])->assertForbidden();

        $this->assertSame(0, ContactActivity::count());
    }

    public function test_the_form_offers_their_pending_applications_newest_first(): void
    {
        $older = $this->application(daysAgo: 9);
        $newer = $this->application(daysAgo: 2);
        $done = $this->application(daysAgo: 30, attributes: ['status' => LeafApplication::STATUS_COMPLETE]);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}")
            ->assertSee('<a class="button dark small" href="' . route('admin.contacts.activities.create', $this->contact->id) . '">Log Activity</a>', false);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}/activities/new")
            ->assertOk()
            ->assertSee('<h1>Log Activity</h1>', false)
            ->assertSee('Jamie Rivera · (970) 555-0123')
            ->assertSee('action="' . route('admin.contacts.activities.store', $this->contact->id) . '"', false)
            ->assertSee('value="text" checked>', false)
            ->assertSee('value="outgoing" checked>', false)
            // Now, in Fort Collins time
            ->assertSee('value="2026-11-06T15:05"', false)
            ->assertSeeInOrder([
                '<option value="' . $newer->id . '" selected>' . $newer->id . ' · Pickup · applied Nov 4</option>',
                '<option value="' . $older->id . '" >' . $older->id . ' · Pickup · applied Oct 28</option>',
                'None',
            ], false)
            ->assertDontSee('<option value="' . $done->id . '"', false)
            ->assertSee('name="follow_up_on"', false);
    }

    public function test_without_pending_applications_the_form_leaves_them_out(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}/activities/new")
            ->assertOk()
            ->assertDontSee('name="leaf_application_id"', false)
            ->assertDontSee('name="follow_up_on"', false);
    }

    public function test_logs_a_text_from_us(): void
    {
        $application = $this->application();

        $this->log(['body' => 'Hi Jamie, are your bags out front?', 'leaf_application_id' => $application->id])
            ->assertRedirect(route('admin.contacts.show', $this->contact->id))
            ->assertSessionHas('status', 'Logged a text.');

        $activity = ContactActivity::sole();
        $this->assertSame($this->contact->id, $activity->contact_id);
        $this->assertSame(ContactActivity::TYPE_TEXT, $activity->type);
        $this->assertSame(ContactActivity::DIRECTION_OUTGOING, $activity->direction);
        $this->assertTrue(Carbon::parse('2026-11-06 14:30', 'America/Denver')->eq($activity->happened_at));
        $this->assertSame('Hi Jamie, are your bags out front?', $activity->body);
        $this->assertSame($application->id, $activity->leaf_application_id);
        $this->assertSame($this->admin->id, $activity->logged_by);
        $this->assertNull($activity->bag_count);
        // Only a pickup or drop-off completes it
        $this->assertSame(LeafApplication::STATUS_PENDING, $application->fresh()->status);
    }

    public function test_a_pickup_needs_bags_and_completes_its_application(): void
    {
        $application = $this->application();

        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'leaf_application_id' => $application->id])
            ->assertSessionHasErrors(['bag_count' => 'Enter how many bags.']);
        $this->assertSame(0, ContactActivity::count());

        // Who reached out doesn't apply to a pickup, so it's left out
        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'direction' => ContactActivity::DIRECTION_INCOMING, 'bag_count' => ' 28 ', 'leaf_application_id' => $application->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Logged a pickup of 28 bags.');

        $activity = ContactActivity::sole();
        $this->assertSame(28, $activity->bag_count);
        $this->assertNull($activity->direction);
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $application->fresh()->status);
    }

    public function test_bags_are_only_kept_for_pickups_and_drop_offs(): void
    {
        $this->log(['type' => ContactActivity::TYPE_NOTE, 'bag_count' => '1,200', 'body' => 'Has a big dog'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Logged a note.');

        $this->assertNull(ContactActivity::sole()->bag_count);
    }

    public function test_save_and_add_another_comes_back_to_the_form(): void
    {
        $application = $this->application();

        $this->log(['type' => ContactActivity::TYPE_CALL, 'direction' => ContactActivity::DIRECTION_INCOMING, 'body' => 'Asked when we can come', 'add_another' => '1'])
            ->assertRedirect(route('admin.contacts.activities.create', $this->contact->id))
            ->assertSessionHas('status', 'Logged a call.');

        // Ready for the next one: the same kind and direction, a new time, and no text
        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}/activities/new")
            ->assertSee('Logged a call.')
            ->assertSee('value="call" checked>', false)
            ->assertSee('value="incoming" checked>', false)
            ->assertSee('value="2026-11-06T15:05"', false)
            ->assertDontSee('Asked when we can come')
            // None was chosen, so None stays chosen
            ->assertSee('<option value="" selected>None</option>', false)
            ->assertDontSee('<option value="' . $application->id . '" selected>', false);
    }

    public function test_a_follow_up_date_goes_on_the_application_chosen(): void
    {
        $chosen = $this->application(daysAgo: 2);
        $other = $this->application(daysAgo: 5);

        $this->log(['leaf_application_id' => $chosen->id, 'follow_up_on' => '2026-11-13'])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-13', $chosen->fresh()->follow_up_on->toDateString());
        $this->assertNull($other->fresh()->follow_up_on);
    }

    public function test_a_follow_up_date_without_an_application_goes_on_all_their_pending_ones(): void
    {
        $first = $this->application(daysAgo: 2);
        $second = $this->application(daysAgo: 5);
        $done = $this->application(daysAgo: 30, attributes: ['status' => LeafApplication::STATUS_COMPLETE]);
        $someoneElses = $this->application(contact: Contact::create(['name' => 'Sam Lee']));

        $this->log(['leaf_application_id' => '', 'follow_up_on' => '2026-11-06'])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-06', $first->fresh()->follow_up_on->toDateString());
        $this->assertSame('2026-11-06', $second->fresh()->follow_up_on->toDateString());
        $this->assertNull($done->fresh()->follow_up_on);
        $this->assertNull($someoneElses->fresh()->follow_up_on);
    }

    public function test_errors_are_shown_and_input_is_kept(): void
    {
        $someoneElses = $this->application(contact: Contact::create(['name' => 'Sam Lee']));

        $this->actingAs($this->admin)
            ->from("/admin/contacts/{$this->contact->id}/activities/new")
            ->post("/admin/contacts/{$this->contact->id}/activities", [
                'type' => 'email',
                'happened_at' => 'yesterday',
                'leaf_application_id' => $someoneElses->id,
                'follow_up_on' => '2026-11-05',
            ])
            ->assertRedirect("/admin/contacts/{$this->contact->id}/activities/new")
            ->assertSessionHasErrors([
                'type' => 'Choose what happened: a text, call, visit, note, pickup, or drop-off.',
                'happened_at' => 'Enter the date and time it happened.',
                'leaf_application_id' => 'Choose one of this contact’s applications, or None.',
                'follow_up_on' => 'Choose today or a later date to follow up.',
            ]);

        $this->log(['direction' => ''])->assertSessionHasErrors(['direction' => 'Choose who reached out: we did, or they did.']);
        // Later today, in Fort Collins time
        $this->log(['happened_at' => '2026-11-06T16:00'])->assertSessionHasErrors(['happened_at' => 'Enter a time that has already happened.']);
        $this->assertSame(0, ContactActivity::count());

        // On a request of its own, since asserting session errors leaves none for the next one
        $this->actingAs($this->admin)
            ->from("/admin/contacts/{$this->contact->id}/activities/new")
            ->followingRedirects()
            ->post("/admin/contacts/{$this->contact->id}/activities", ['type' => 'pickup', 'happened_at' => '2026-11-06T09:15', 'body' => 'Left at the curb'])
            ->assertSee('Enter how many bags.')
            ->assertSee('value="pickup" checked>', false)
            ->assertSee('value="2026-11-06T09:15"', false)
            ->assertSee('>Left at the curb</textarea>', false);
    }

    public function test_another_or_deleted_contact_is_not_found(): void
    {
        $deleted = Contact::create(['name' => 'Sam Lee']);
        $deleted->delete();

        $this->actingAs($this->admin)->get("/admin/contacts/{$deleted->id}/activities/new")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/contacts/{$deleted->id}/activities", ['type' => 'note', 'happened_at' => '2026-11-06T14:30'])->assertNotFound();
    }

    public function test_the_timeline_lists_entries_applications_and_form_errors_newest_first(): void
    {
        $application = $this->application(daysAgo: 2);
        $submission = FormSubmission::create([
            'reference' => 'F7K2QX',
            'form' => FormSubmission::FORM_LEAF_APPLICATION,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'data' => ['phone' => '(970) 555-0123'],
            'outcome' => FormSubmission::OUTCOME_REJECTED,
        ]);
        $submission->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->log(['happened_at' => '2026-11-05T18:10', 'body' => 'Hi Jamie, are your bags <b>out front</b>?']);
        $this->log(['direction' => ContactActivity::DIRECTION_INCOMING, 'happened_at' => '2026-11-05T19:42', 'body' => "By the side gate.\nThere are 32."]);
        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'bag_count' => '32', 'happened_at' => '2026-11-06T09:00', 'leaf_application_id' => $application->id]);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}")
            ->assertSeeInOrder([
                '<h2>Timeline</h2>',
                'Nov 6, 9:00 AM', 'Pickup: 32 bags · Rachel', 'Application ' . $application->id,
                'Nov 5, 7:42 PM', 'Text from Jamie Rivera', "By the side gate.\nThere are 32.",
                'Nov 5, 6:10 PM', 'Text from us · Rachel', 'Hi Jamie, are your bags &lt;b&gt;out front&lt;/b&gt;?',
                'Applied for a pickup: 30 bags, ready now', 'Application ' . $application->id,
                'Form error: leaf application, rejected', 'F7K2QX',
                '<h2>Applications</h2>',
            ], false)
            ->assertSee('<li class="incoming">', false)
            ->assertSee('<li class="automatic">', false)
            // The pickup completed the application
            ->assertSee('<td class="">Complete</td>', false);
    }

    public function test_a_contact_with_nothing_yet_says_so(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$this->contact->id}")
            ->assertSeeInOrder(['<h2>Timeline</h2>', 'Nothing logged yet.'], false);
    }
}
