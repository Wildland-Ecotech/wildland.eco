<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Leaves dashboard: the season's totals, the bags still waiting,
 * and the latest ledger entries and applications.
 */
class AdminLeavesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
    }

    /**
     * A pickup or drop-off for the contact, or with no contact, at a Fort
     * Collins time.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(?Contact $contact, string $type, string $when, int $bags, array $attributes = []): ContactActivity
    {
        $entry = new ContactActivity;
        $entry->forceFill([
            'contact_id' => $contact?->id,
            'type' => $type,
            // Stored in UTC, as the timeline form stores it
            'happened_at' => Carbon::parse($when, 'America/Denver')->utc(),
            'bag_count' => $bags,
            'logged_by' => $this->admin->id,
            ...$attributes,
        ])->save();

        return $entry;
    }

    /**
     * An application with a name, estimated bags, and status.
     */
    private function application(string $name, int $bags, string $status = LeafApplication::STATUS_PENDING): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => $name,
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => $bags,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            'status' => $status,
        ])->save();

        return $application;
    }

    public function test_only_signed_in_admins_see_the_dashboard(): void
    {
        $this->get('/admin/leaves')->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->get('/admin/leaves')->assertForbidden();
    }

    public function test_shows_the_seasons_totals_and_the_bags_still_waiting(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $this->entry($contact, ContactActivity::TYPE_PICKUP, '2026-10-25 10:00', 14);
        $this->entry($contact, ContactActivity::TYPE_DROP_OFF, '2026-11-08 09:19', 6);
        // Last season isn't counted
        $this->entry($contact, ContactActivity::TYPE_PICKUP, '2025-11-15 10:00', 40);

        $this->application('Alex Morgan', 12);
        $this->application('Pat Lee', 8, LeafApplication::STATUS_COMPLETE);
        $this->application('Probe Bot', 30)->flag($this->admin, LeafApplication::FLAG_SPAM);

        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertOk()
            ->assertSee('<a href="' . route('admin.leaves') . '" aria-current="page">Leaves</a>', false)
            ->assertSee('<h2>Autumn 2026</h2>', false)
            ->assertSee('The season so far, since July 1, 2026.')
            ->assertSeeInOrder([
                'href="' . route('admin.leaves.ledger') . '"', '<span class="count">20</span>', 'Bags collected',
                'href="' . route('admin.leaves.ledger') . '"', '<span class="count">2</span>', 'Pickups and drop-offs',
                'href="' . route('admin.leaves.applications', ['period' => 'all']) . '"', '<span class="count">12</span>', 'Bags still waiting',
            ], false);
    }

    public function test_lists_the_latest_entries_and_applications_newest_first(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);

        foreach (range(1, 6) as $day) {
            $this->entry($contact, ContactActivity::TYPE_PICKUP, "2026-11-0{$day} 10:00", $day);
            $application = $this->application("Applicant {$day}", $day);
            $application->forceFill(['created_at' => Carbon::parse("2026-11-0{$day} 09:00", 'America/Denver')->utc()])->save();
        }

        $response = $this->actingAs($this->admin)->get('/admin/leaves');

        $response->assertOk()
            ->assertSeeInOrder([
                'Latest Ledger Entries', 'Nov 6, 10:00 AM', 'Nov 5, 10:00 AM', 'Nov 2, 10:00 AM',
                'href="' . route('admin.leaves.ledger') . '">Leaf Ledger</a>',
                'Latest Applications', 'Applicant 6', 'Applicant 5', 'Applicant 2',
                'href="' . route('admin.leaves.applications') . '">Leaf Applications</a>',
            ], false)
            ->assertSee('<a href="' . route('admin.contacts.show', $contact->id) . '">Jamie Rivera</a>', false)
            ->assertDontSee('Nov 1, 10:00 AM')
            ->assertDontSee('Applicant 1<', false);
    }

    public function test_drop_offs_with_no_contact_count_and_are_unknown(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2026-11-02 10:00', 14);
        $this->entry(null, ContactActivity::TYPE_DROP_OFF, '2026-11-08 09:19', 6, ['drop_off_site_id' => $site->id]);

        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertOk()
            ->assertSeeInOrder(['<span class="count">20</span>', 'Bags collected', '<span class="count">2</span>', 'Pickups and drop-offs'], false)
            ->assertSeeInOrder(['Latest Ledger Entries', 'Nov 8, 9:19 AM', 'Unknown', 'Nov 2, 10:00 AM', 'Jamie Rivera'])
            ->assertDontSee('>Unknown</a>', false);
    }

    public function test_says_when_nothing_is_logged_or_applied_for(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertOk()
            ->assertSee('No pickups or drop-offs logged in Autumn 2026.')
            ->assertSee('No applications yet.');
    }
}
