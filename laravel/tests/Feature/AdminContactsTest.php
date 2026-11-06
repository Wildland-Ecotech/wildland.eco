<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin contact pages: the list of contacts with a search
 * (/admin/contacts), and one contact (/admin/contacts/{id}).
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

    public function test_lists_the_most_recent_applicant_first_and_contacts_without_one_last(): void
    {
        $older = $this->contact('Sam Lee', '(970) 555-0144', 'sam@example.com');
        $this->application($older, daysAgo: 6);
        $recent = $this->contact('Jamie Rivera', '(970) 555-0123', 'jamie@example.com');
        $this->application($recent, daysAgo: 2);
        $this->application($recent, daysAgo: 20);
        $this->contact('Taylor Brooks', '(970) 555-0177');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee('3 contacts, most recent application first.')
            ->assertSeeInOrder(['Jamie Rivera', 'Sam Lee', 'Taylor Brooks'])
            ->assertSee('<td class="numeric">2</td>', false)
            ->assertSee('Nov 3, 7:30 PM')
            ->assertSee('href="' . route('admin.contacts.show', $recent->id) . '"', false)
            ->assertSee('<summary>Nov 3 · Jamie Rivera · (970) 555-0123</summary>', false)
            // Without an application, the line starts with the name
            ->assertSee('<summary>Taylor Brooks · (970) 555-0177</summary>', false);
    }

    public function test_the_list_shows_the_main_phone_number_and_email(): void
    {
        $contact = $this->contact('Jamie Rivera', '(970) 555-0123', 'old@example.com');
        $contact->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('jamie@example.com')
            ->assertDontSee('old@example.com');
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
            ->assertSee('<dd>(970) 555-0123</dd>', false)
            ->assertSee('<dd>jamie@example.com</dd>', false)
            ->assertSee('<dd>123 Example St, Fort Collins, CO 80521</dd>', false)
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
            ->assertSee("<dd>(970) 555-0188 (main)\n(970) 555-0123</dd>", false);
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
