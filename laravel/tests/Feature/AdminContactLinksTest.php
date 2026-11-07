<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use App\Services\ContactLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Links to contacts across the admin pages: names on the Leaf Applications
 * and Error Logs lists, the Contact row of an error's page, and a contact's
 * Form Errors.
 */
class AdminContactLinksTest extends TestCase
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
     * A leaf application form submission, made $minutesAgo minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submission(string $reference, string $outcome, array $attributes = [], int $minutesAgo = 60): FormSubmission
    {
        $submission = FormSubmission::create([
            'reference' => $reference,
            'form' => FormSubmission::FORM_LEAF_APPLICATION,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'data' => ['phone' => '(970) 555-0123'],
            'outcome' => $outcome,
            ...$attributes,
        ]);

        $submission->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $submission;
    }

    public function test_application_names_link_to_their_contacts(): void
    {
        $linked = $this->apply();
        $unlinked = LeafApplication::create([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'phone' => '(970) 555-0144',
            'address' => '9 Sample Rd, Fort Collins, CO 80526',
            'estimated_bag_count' => 5,
            'ready_now' => true,
            'terms_accepted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertSee('<td class=""><a href="' . route('admin.contacts.show', $linked->contact_id) . '">Jamie Rivera</a></td>', false)
            ->assertSee('<td class="">Sam Lee</td>', false)
            // The ID still opens the application
            ->assertSee('href="' . route('admin.leaves.show', $unlinked->id) . '"', false);
    }

    public function test_error_log_names_link_to_the_contact_with_their_email_or_phone_number(): void
    {
        $jamie = $this->apply()->contact;
        $this->submission('BYMAIL', FormSubmission::OUTCOME_REJECTED, ['data' => []]);
        $this->submission('BYFONE', FormSubmission::OUTCOME_REJECTED, ['name' => 'J. Rivera', 'email' => 'other@example.com', 'data' => ['phone' => '+1 970 555 0123']]);
        $this->submission('NOBODY', FormSubmission::OUTCOME_REJECTED, ['name' => 'Sam Lee', 'email' => 'sam@example.com', 'data' => ['phone' => '970-555-0144']]);

        $link = route('admin.contacts.show', $jamie->id);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<td class=""><a href="' . $link . '">Jamie Rivera</a></td>', false)
            ->assertSee('<td class=""><a href="' . $link . '">J. Rivera</a></td>', false)
            ->assertSee('<td class="">Sam Lee</td>', false)
            // The phone's line keeps the name as words
            ->assertSee('Nov 5 · J. Rivera · ', false);
    }

    public function test_an_errors_page_links_to_the_contact(): void
    {
        $jamie = $this->apply()->contact;
        $this->submission('BYMAIL', FormSubmission::OUTCOME_REJECTED);
        $this->submission('NOBODY', FormSubmission::OUTCOME_REJECTED, ['name' => 'Sam Lee', 'email' => 'sam@example.com', 'data' => []]);

        $this->actingAs($this->admin)
            ->get('/admin/errors/BYMAIL')
            ->assertSeeInOrder(['<dt>Name</dt>', '<dt>Contact</dt>', '<dd><a href="' . route('admin.contacts.show', $jamie->id) . '">Jamie Rivera</a></dd>', '<dt>Email</dt>'], false);

        $this->actingAs($this->admin)
            ->get('/admin/errors/NOBODY')
            ->assertSeeInOrder(['<dt>Contact</dt>', '<dd>—</dd>'], false);
    }

    public function test_a_contacts_page_lists_their_form_errors_and_follow_ups(): void
    {
        $jamie = $this->apply()->contact;
        $jamie->addDetail(ContactDetail::TYPE_EMAIL, 'jr@example.com');
        // By an email, by the phone number written another way, and one
        // closed as resolved; then one that went through, and someone else's
        $this->submission('BYMAIL', FormSubmission::OUTCOME_REJECTED, ['email' => 'jr@example.com', 'data' => []], minutesAgo: 30);
        $this->submission('BYFONE', FormSubmission::OUTCOME_EXPIRED, ['email' => 'other@example.com', 'data' => ['phone' => '970.555.0123']], minutesAgo: 90);
        $this->submission('CLOSED', FormSubmission::OUTCOME_REJECTED, minutesAgo: 120)->close($this->admin, FormSubmission::RESOLUTION_RESOLVED);
        $this->submission('SAVED1', FormSubmission::OUTCOME_SAVED, minutesAgo: 10);
        $this->submission('NOBODY', FormSubmission::OUTCOME_REJECTED, ['name' => 'Sam Lee', 'email' => 'sam@example.com', 'data' => ['phone' => '970-555-0144']]);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$jamie->id}")
            ->assertSeeInOrder(['Applications', 'Form Errors', 'BYMAIL', 'BYFONE', 'CLOSED'])
            ->assertSee('href="' . route('admin.errors.show', 'BYMAIL') . '"', false)
            ->assertSee('Resolved by')
            ->assertSee('<summary><span class="status-icon failure" aria-hidden="true">✕</span><span class="visually-hidden">No record: </span>Nov 5 · Leaf application · ', false)
            ->assertDontSee('SAVED1')
            ->assertDontSee('NOBODY');
    }

    public function test_a_contact_without_form_errors_shows_no_section(): void
    {
        $jamie = $this->apply()->contact;

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$jamie->id}")
            ->assertDontSee('Form Errors');
    }
}
