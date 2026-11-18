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
 * Needs Review (/admin/contacts/review): applications whose contact needs an
 * admin to look, drop-offs with no site, and drop-offs with no contact, the
 * buttons to it, and the flags on the Leaf Ledger and Leaf Applications.
 */
class AdminContactReviewTest extends TestCase
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
     * An application made $daysAgo days ago, flagged for these reasons, and
     * linked to the contact if one is given.
     *
     * @param  list<string>  $reasons
     */
    private function flagged(string $name, array $reasons, int $daysAgo, ?Contact $contact = null): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $name,
            'email' => 'applicant@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now(),
        ]);

        $application->forceFill([
            'contact_id' => $contact?->id,
            'contact_review' => $reasons,
            'created_at' => now()->subDays($daysAgo),
        ])->save();

        return $application;
    }

    public function test_only_signed_in_admins_can_see_it(): void
    {
        $this->get('/admin/contacts/review')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->withPassword()->create())->get('/admin/contacts/review')->assertForbidden();
    }

    public function test_lists_applications_that_need_review_oldest_first(): void
    {
        $alex = Contact::create(['name' => 'Alex Rivera']);
        $newer = $this->flagged('Jamie Rivera', [LeafApplication::REVIEW_CONFLICT], daysAgo: 1);
        $older = $this->flagged('Alex Rivera', [LeafApplication::REVIEW_SHARED_EMAIL, LeafApplication::REVIEW_SHARED_PHONE], daysAgo: 3, contact: $alex);
        // Reviewed already
        $this->flagged('Sam Lee', [LeafApplication::REVIEW_ADDRESS], daysAgo: 2)->forceFill(['contact_reviewed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertOk()
            ->assertSee('<h1>Needs Review</h1>', false)
            ->assertSee('aria-current="page">Contacts</a>', false)
            ->assertSee('2 applications need review, oldest first.')
            ->assertSeeInOrder([route('admin.leaves.show', $older->id), route('admin.leaves.show', $newer->id)])
            // The contact links to their page; an application without one says so
            ->assertSee('<td class=""><a href="' . route('admin.contacts.show', $alex->id) . '">Alex Rivera</a></td>', false)
            ->assertSee('<td class="">Not linked</td>', false)
            ->assertSee('Email also on another contact; Phone number also on another contact')
            ->assertSee('<summary>Nov 4 · Jamie Rivera · Matches more than one contact with this name</summary>', false)
            ->assertDontSee('Sam Lee');
    }

    public function test_says_when_nothing_needs_review(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertSee('Nothing needs review.')
            ->assertDontSee('<table', false);
    }

    public function test_the_contact_list_links_to_it_only_when_something_needs_review(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertDontSee('Needs Review');

        $this->flagged('Jamie Rivera', [LeafApplication::REVIEW_CONFLICT], daysAgo: 1);
        $this->flagged('Alex Rivera', [LeafApplication::REVIEW_SHARED_PHONE], daysAgo: 200);

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('<a class="button dark small" href="' . route('admin.contacts.review') . '">Needs Review (2)</a>', false);
    }

    /**
     * A drop-off at a Fort Collins time, for the contact or with none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dropOff(?Contact $contact, string $when, array $attributes = []): ContactActivity
    {
        $entry = new ContactActivity;
        $entry->forceFill([
            'contact_id' => $contact?->id,
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => Carbon::parse($when, 'America/Denver')->utc(),
            'bag_count' => 6,
            'logged_by' => $this->admin->id,
            ...$attributes,
        ])->save();

        return $entry;
    }

    public function test_lists_drop_offs_with_no_site_and_bags_to_identify(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $deleted = Contact::create(['name' => 'Spammy Spammer']);

        $noSite = $this->dropOff($jamie, '2026-10-27 14:51', ['bag_count' => 24, 'bag_brand' => 'Walmart']);
        $unknown = $this->dropOff(null, '2026-11-04 09:00', ['drop_off_site_id' => $site->id, 'bag_brand' => 'Home Depot', 'body' => 'By the gate']);
        // Not needing review: drop-offs with a site, deleted entries, entries of deleted contacts, and pickups
        $this->dropOff($jamie, '2026-11-01 09:00', ['drop_off_site_id' => $site->id]);
        $this->dropOff($jamie, '2026-11-02 09:00')->delete();
        $this->dropOff($deleted, '2026-11-03 09:00');
        $deleted->delete();
        $this->dropOff($jamie, '2026-11-03 10:00', ['type' => ContactActivity::TYPE_PICKUP]);

        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertOk()
            ->assertSee('1 drop-off with no site and 1 drop-off with no contact need review, oldest first.')
            ->assertDontSee('<h2 id="applications">', false)
            ->assertSeeInOrder([
                '<h2 id="no-site">Drop-offs With No Site</h2>',
                '<a class="record-link" href="' . route('admin.activities.edit', $noSite->id) . '">Oct 27, 2:51 PM</a>',
                '<a href="' . route('admin.contacts.show', $jamie->id) . '">Jamie Rivera</a>',
                '24',
                'Walmart',
                '<h2 id="unknown">Bags to Identify</h2>',
                '<a class="record-link" href="' . route('admin.activities.edit', $unknown->id) . '">Nov 4, 9:00 AM</a>',
                'North Drop-off Site',
                'Home Depot',
                'By the gate',
            ], false)
            ->assertSee('Edit Entry')
            ->assertDontSee('Spammy Spammer');
    }

    public function test_counts_each_kind_in_the_summary(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $this->flagged('Jamie Rivera', [LeafApplication::REVIEW_CONFLICT], daysAgo: 1);
        $this->dropOff(Contact::create(['name' => 'Sam Lee']), '2026-11-01 09:00');

        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertSee('1 application and 1 drop-off with no site need review, oldest first.');

        $this->dropOff(null, '2026-11-02 09:00', ['drop_off_site_id' => $site->id]);
        $this->dropOff(null, '2026-11-02 10:00', ['drop_off_site_id' => $site->id]);

        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertSee('1 application, 1 drop-off with no site, and 2 drop-offs with no contact need review, oldest first.')
            ->assertSeeInOrder(['<h2 id="applications">Applications</h2>', '<h2 id="no-site">', '<h2 id="unknown">'], false);
    }

    public function test_saving_an_entry_from_needs_review_comes_back(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $entry = $this->dropOff($jamie, '2026-10-27 14:51');

        $this->actingAs($this->admin)
            ->from(route('admin.contacts.review'))
            ->get(route('admin.activities.edit', $entry->id))
            ->assertSee('<input type="hidden" name="return_to" value="/admin/contacts/review">', false);

        $this->actingAs($this->admin)
            ->post(route('admin.activities.update', $entry->id), [
                'contact_id' => $jamie->id,
                'type' => ContactActivity::TYPE_DROP_OFF,
                'happened_at' => '2026-10-27T14:51',
                'bag_count' => '6',
                'drop_off_site_id' => $site->id,
                'return_to' => '/admin/contacts/review',
            ])
            ->assertRedirect('/admin/contacts/review');

        $this->actingAs($this->admin)
            ->get('/admin/contacts/review')
            ->assertSee('Nothing needs review.')
            ->assertSee('Saved the drop-off of 6 bags from Oct 27.');
    }

    public function test_the_buttons_count_everything_and_rows_are_flagged(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $this->dropOff($jamie, '2026-11-01 09:00');
        $this->dropOff(null, '2026-11-02 09:00', ['drop_off_site_id' => $site->id]);
        $flagged = $this->flagged('Alex Rivera', [LeafApplication::REVIEW_SHARED_PHONE], daysAgo: 1, contact: $jamie);
        $button = '<a class="button dark small" href="' . route('admin.contacts.review') . '">Needs Review (3)</a>';

        $this->actingAs($this->admin)->get('/admin/contacts')->assertSee($button, false);

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger')
            ->assertSee($button, false)
            ->assertSeeInOrder(['Nov 1, 9:00 AM</a> <span class="badge soon">Needs site</span>', 'Nov 2, 9:00 AM</a> <span class="badge soon">To identify</span>'], false);

        $this->actingAs($this->admin)
            ->get('/admin/leaves/applications')
            ->assertSee($button, false)
            ->assertSee('<span class="badge soon">Needs review</span> Phone number also on another contact', false)
            ->assertSee(route('admin.leaves.show', $flagged->id));
    }

    public function test_the_ledger_and_leaf_applications_leave_out_the_button_when_nothing_needs_review(): void
    {
        $this->actingAs($this->admin)->get('/admin/leaves/ledger')->assertDontSee('Needs Review');
        $this->actingAs($this->admin)->get('/admin/leaves/applications')->assertDontSee('Needs Review');
    }
}
