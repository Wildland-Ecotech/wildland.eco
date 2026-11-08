<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin contact pages: the list of every contact with a search
 * (/admin/contacts), those who need follow-up first, and one contact
 * (/admin/contacts/{id}).
 */
class AdminContactsTest extends TestCase
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
     * A contact with a primary phone number and email, if given.
     */
    private function contact(string $name, ?string $phone = null, ?string $email = null): Contact
    {
        $contact = Contact::create(['name' => $name]);

        if ($phone !== null) {
            $contact->usePrimary(ContactDetail::TYPE_PHONE, $phone);
        }

        if ($email !== null) {
            $contact->usePrimary(ContactDetail::TYPE_EMAIL, $email);
        }

        return $contact;
    }

    /**
     * An application by the contact, made $daysAgo days ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(Contact $contact, int $daysAgo, array $attributes = []): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $contact->name,
            'email' => 'applicant@example.com',
            'phone' => '(970) 555-0100',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 40,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            ...$attributes,
        ]);

        $application->forceFill(['contact_id' => $contact->id, 'created_at' => now()->subDays($daysAgo)])->save();

        return $application;
    }

    public function test_only_signed_in_admins_can_see_contacts(): void
    {
        $contact = $this->contact('Jamie Rivera');
        $pages = ['/admin/contacts', "/admin/contacts/{$contact->id}"];

        // Every page as a guest first, since signing in lasts for the rest of the test
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }
    }

    public function test_contacts_follow_the_dashboard_in_the_admin_links(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSeeInOrder(['>Dashboard</a>', '>Contacts</a>', '>Leaf Applications</a>', '>Donations</a>', '>Error Logs</a>'], false);

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('aria-current="page">Contacts</a>', false);
    }

    public function test_lists_every_contact_with_those_who_need_follow_up_first(): void
    {
        $ready = $this->contact('Jamie Rivera', '(970) 555-0123');
        $this->application($ready, daysAgo: 1);
        $waiting = $this->contact('Sam Lee', '(970) 555-0144');
        $this->application($waiting, daysAgo: 9);
        // Nothing pending, or no applications at all: after those who need follow-up
        $done = $this->contact('Alex Moreno', '(970) 555-0199');
        // The status isn't fillable, as only a logged pickup or drop-off sets it
        $this->application($done, daysAgo: 3)->forceFill(['status' => LeafApplication::STATUS_COMPLETE])->save();
        $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertOk()
            // Applied as soon as it changes, with JavaScript
            ->assertSee('<select id="sort" name="sort" data-auto-submit>', false)
            ->assertSee('<option value="follow-up" selected>Needs follow-up first</option>', false)
            ->assertSee('4 contacts. The 2 waiting for a pickup or drop-off are first, who needs to hear from us first at the top.')
            ->assertSeeInOrder(['>Name</th>', '>Applied for</th>', '>Follow-up</th>', '>Bags pending</th>', '>Bags donated</th>', '>Applications</th>', '>Latest application</th>'], false)
            ->assertSeeInOrder([
                // Red for those waiting on us, yellow for bags ready
                'Sam Lee</a> <span class="badge urgent">Waiting on us</span>', 'Pickup', 'No word from us in 9 days', '40',
                // Before daylight saving time ended, so an hour later on the clock
                'Oct 27, 8:30 PM',
                'Jamie Rivera</a> <span class="badge soon">Ready</span>', 'Bags ready now',
                // Then everyone else, the most recent applicant first
                'Alex Moreno</a>', 'Taylor Brooks</a>',
            ], false)
            ->assertSee('<summary>Sam Lee · No word from us in 9 days <span class="badge urgent">Waiting on us</span></summary>', false)
            ->assertSee('<summary>Nov 2 · Alex Moreno</summary>', false)
            // Without an application, the line is the name alone
            ->assertSee('<summary>Taylor Brooks</summary>', false);
    }

    public function test_sorts_by_the_most_recent_applicant_with_contacts_without_one_last(): void
    {
        $older = $this->contact('Sam Lee', '(970) 555-0144', 'sam@example.com');
        $this->application($older, daysAgo: 6);
        $recent = $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');
        $this->application($recent, daysAgo: 2);
        $this->application($recent, daysAgo: 20);
        $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get('/admin/contacts?sort=recent')
            ->assertOk()
            ->assertSee('<option value="recent" selected>Most recent application</option>', false)
            ->assertSee('3 contacts, most recent application first.')
            // Both need follow-up, and keep their labels, but Sam Lee isn't
            // first. Jamie Rivera's older application, still pending after
            // 20 days, puts them in Waiting on us.
            ->assertSeeInOrder(['Jamie Rivera</a> <span class="badge urgent">Waiting on us</span>', 'Sam Lee</a> <span class="badge soon">Ready</span>', 'Taylor Brooks'], false)
            ->assertSee('<td class="numeric">2</td>', false)
            ->assertSee('Nov 3, 7:30 PM')
            ->assertSee('href="' . route('admin.contacts.show', $recent->id) . '"', false);
    }

    public function test_the_list_leaves_out_phone_numbers_and_emails(): void
    {
        $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('Jamie Rivera')
            ->assertDontSee('555-0123')
            ->assertDontSee('jamie@example.com')
            ->assertDontSee('>Phone</th>', false)
            ->assertDontSee('>Email</th>', false);
    }

    public function test_shows_the_latest_application_type_and_bags(): void
    {
        $contact = $this->contact('Jamie Rivera');
        // Done: its bags were donated, and aren't pending
        $done = $this->application($contact, daysAgo: 20, attributes: ['estimated_bag_count' => 30]);
        $contact->activities()->create(['type' => ContactActivity::TYPE_PICKUP, 'happened_at' => now()->subDays(15), 'bag_count' => 28, 'leaf_application_id' => $done->id]);
        $contact->activities()->create(['type' => ContactActivity::TYPE_DROP_OFF, 'happened_at' => now()->subDays(10), 'bag_count' => 5]);
        // A deleted entry isn't counted
        $contact->activities()->create(['type' => ContactActivity::TYPE_PICKUP, 'happened_at' => now()->subDays(9), 'bag_count' => 100])->delete();
        $this->application($contact, daysAgo: 4, attributes: ['estimated_bag_count' => 15]);
        $this->application($contact, daysAgo: 2, attributes: ['type' => LeafApplication::TYPE_DROP_OFF, 'estimated_bag_count' => 12]);
        $this->contact('Taylor Brooks');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSeeInOrder([
                // The latest is a drop-off, with 15 and 12 bags pending and 28
                // and 5 donated. Cells without a class get an empty one from @class.
                'Jamie Rivera</a>', '<td class="">Drop-off</td>', '<td class="numeric">27</td>', '<td class="numeric">33</td>', '<td class="numeric">3</td>',
                // No applications, and nothing donated
                'Taylor Brooks</a>', '<td class="">—</td>', '<td class="">—</td>', '<td class="numeric">—</td>', '<td class="numeric">—</td>', '<td class="numeric">0</td>',
            ], false);
    }

    public function test_searches_names_emails_and_phone_numbers(): void
    {
        $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');
        $this->contact('Alex Moreno', '970.555.0199', 'alex@example.org');

        $searches = [
            'rivera' => 'Jamie Rivera',
            'ALEX@' => 'Alex Moreno',
            'example.org' => 'Alex Moreno',
            // Digits match however the number was written
            '970-555-0199' => 'Alex Moreno',
            '5550123' => 'Jamie Rivera',
        ];

        foreach ($searches as $search => $name) {
            $other = $name === 'Jamie Rivera' ? 'Alex Moreno' : 'Jamie Rivera';

            $this->actingAs($this->admin)
                ->get('/admin/contacts?q=' . urlencode($search))
                ->assertSee("1 contact matching “{$search}”", false)
                ->assertSee($name)
                ->assertDontSee($other);
        }
    }

    public function test_search_finds_previous_phone_numbers(): void
    {
        $contact = $this->contact('Jamie Rivera', '(970) 555-0123');
        $contact->findDetail(ContactDetail::TYPE_PHONE, '9705550123')->forceFill(['is_primary' => false, 'removed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get('/admin/contacts?q=555-0123')
            ->assertSee('Jamie Rivera');
    }

    public function test_search_wildcards_are_taken_literally(): void
    {
        $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');

        $this->actingAs($this->admin)
            ->get('/admin/contacts?q=%25')
            ->assertSee('No contacts match “%”.', false)
            ->assertSee('value="%"', false);
    }

    public function test_says_when_there_are_no_contacts(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee('No contacts yet.')
            ->assertDontSee('<table', false);
    }

    public function test_drop_off_sites_are_labeled(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $site->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0100');
        $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('<a class="record-link" href="' . route('admin.contacts.show', $site->id) . '">North Drop-off Site</a> <span class="badge">Drop-off site</span>', false)
            ->assertSee('<summary>North Drop-off Site <span class="badge">Drop-off site</span></summary>', false)
            ->assertSee('<summary>Taylor Brooks</summary>', false);
    }

    public function test_a_contact_is_listed_once_by_their_first_application_in_order(): void
    {
        $contact = $this->contact('Jamie Rivera', '(970) 555-0123');
        $this->application($contact, daysAgo: 1, attributes: ['estimated_bag_count' => 15]);
        $this->application($contact, daysAgo: 10, attributes: ['estimated_bag_count' => 60]);

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('1 contact. The one waiting for a pickup or drop-off is first.')
            ->assertSee('No word from us in 10 days')
            ->assertDontSee('Bags ready now')
            // Both applications' bags are pending
            ->assertSee('<td class="numeric">75</td>', false);
    }

    public function test_says_why_each_contact_needs_follow_up(): void
    {
        // Why, and the label with its tone
        $cases = [
            'Their text on Nov 4 needs a reply' => [[], [ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_INCOMING, 1], '<span class="badge urgent">Waiting on us</span>'],
            'Reached Nov 4' => [[], [ContactActivity::TYPE_CALL, ContactActivity::DIRECTION_OUTGOING, 1], '<span class="badge">Contacted</span>'],
            'Follow-up due today' => [['follow_up_on' => '2026-11-05'], [ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 1], '<span class="badge urgent">Waiting on us</span>'],
            'Follow up on Nov 12' => [['follow_up_on' => '2026-11-12'], null, '<span class="badge muted">Later</span>'],
            'Bags ready Dec 5' => [['ready_now' => false, 'ready_date' => '2026-12-05'], null, '<span class="badge muted">Later</span>'],
            'Bags ready Nov 8' => [['ready_now' => false, 'ready_date' => '2026-11-08'], null, '<span class="badge soon">Ready</span>'],
        ];

        // A contact for each, applied two days ago
        $names = [];

        foreach ($cases as $why => [$application, $activity, $label]) {
            $contact = $this->contact('Contact ' . (count($names) + 1));
            $names[$why] = [$contact->name, $label];
            $this->application($contact, daysAgo: 2)->forceFill($application)->save();

            if ($activity !== null) {
                [$type, $direction, $daysAgo] = $activity;
                $contact->activities()->create(['type' => $type, 'direction' => $direction, 'happened_at' => now()->subDays($daysAgo)]);
            }
        }

        $response = $this->actingAs($this->admin)->get('/admin/contacts');

        foreach ($names as $why => [$name, $label]) {
            $response->assertSee("<summary>{$name} · {$why} {$label}</summary>", false);
        }
    }

    public function test_search_keeps_those_who_need_follow_up_first(): void
    {
        $this->application($this->contact('Jamie Rivera', '(970) 555-0123'), daysAgo: 1);
        $this->application($this->contact('Sam Rivera', '(970) 555-0144'), daysAgo: 1);
        $this->contact('Alex Rivera', '(970) 555-0177');
        $this->contact('Taylor Brooks', '(970) 555-0188');

        $this->actingAs($this->admin)
            ->get('/admin/contacts?q=rivera')
            ->assertSee('3 contacts matching “rivera”. The 2 waiting for a pickup or drop-off are first, who needs to hear from us first at the top.', false)
            ->assertSeeInOrder(['Jamie Rivera', 'Sam Rivera', 'Alex Rivera'])
            ->assertDontSee('Taylor Brooks');

        $this->actingAs($this->admin)
            ->get('/admin/contacts?q=taylor')
            ->assertSee('1 contact matching “taylor”, most recent application first. Nobody is waiting for a pickup or drop-off.', false)
            ->assertSee('Taylor Brooks');
    }

    public function test_says_when_nobody_needs_follow_up(): void
    {
        $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('1 contact, most recent application first. Nobody is waiting for a pickup or drop-off.')
            ->assertSee('Taylor Brooks');
    }

    public function test_a_contact_page_says_why_they_need_follow_up(): void
    {
        $contact = $this->contact('Sam Lee', '(970) 555-0144');
        $this->application($contact, daysAgo: 9);
        $other = $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertSeeInOrder(['<dt>Follow-up</dt>', '<dd>Waiting on us · No word from us in 9 days</dd>'], false);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$other->id}")
            ->assertDontSee('<dt>Follow-up</dt>', false);
    }

    public function test_a_contact_page_shows_their_details_and_applications(): void
    {
        $contact = $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');
        $contact->moveTo('456 Sample Ave, Fort Collins, CO 80524');
        $contact->moveTo('123 Example St, Fort Collins, CO 80521');
        $older = $this->application($contact, daysAgo: 24, attributes: ['type' => LeafApplication::TYPE_DROP_OFF, 'estimated_bag_count' => 12]);
        $newer = $this->application($contact, daysAgo: 1);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertOk()
            ->assertSee('<h1>Jamie Rivera</h1>', false)
            ->assertSee('aria-current="page">Contacts</a>', false)
            // One of each, so neither is marked as the main one
            ->assertSee('<dd>970-555-0123</dd>', false)
            ->assertSee('<dd>jamie@example.com</dd>', false)
            // The address opens the map
            ->assertSee('<dd><a href="' . route('admin.map', ['contact' => $contact->id]) . '">123 Example St, Fort Collins, CO 80521</a></dd>', false)
            ->assertSeeInOrder(['Previous address', '456 Sample Ave, Fort Collins, CO 80524'])
            ->assertSee('Thu, Nov 5, 2026 7:30 PM MST')
            // Newest first, linking to each application
            ->assertSeeInOrder([route('admin.leaves.show', $newer->id), route('admin.leaves.show', $older->id)])
            ->assertSee('<summary>Oct 12 · Drop-off · 12 bags</summary>', false)
            ->assertSee('<a class="back" href="' . route('admin.contacts') . '">← Back to Contacts</a>', false);
    }

    public function test_the_main_one_is_marked_when_there_are_several(): void
    {
        $contact = $this->contact('Jamie Rivera', '(970) 555-0123');
        $contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0188');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertSee("<dd>970-555-0188 (main)\n970-555-0123</dd>", false);
    }

    public function test_a_contact_without_applications_says_so(): void
    {
        $contact = $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertSee('No applications yet.')
            // Nothing recorded shows as a dash
            ->assertSeeInOrder(['Email', '<dd>—</dd>'], false);
    }

    public function test_an_unknown_contact_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/contacts/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/contacts/abc')->assertNotFound();
    }
}
