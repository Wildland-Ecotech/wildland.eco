<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Needs Review (/admin/contacts/review): applications whose contact needs an
 * admin to look, and the button to it on the contact list.
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
}
