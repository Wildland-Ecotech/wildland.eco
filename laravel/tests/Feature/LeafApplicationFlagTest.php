<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Marking leaf applications as a test or spam: hiding them, closing their
 * form submissions on Error Logs, unmarking them, and deleting a contact
 * whose applications are all marked.
 */
class LeafApplicationFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-12 09:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
    }

    /**
     * A contact with a primary phone number and email.
     */
    private function contact(): Contact
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $contact->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $contact->usePrimary(ContactDetail::TYPE_EMAIL, 'jamie@example.com');

        return $contact;
    }

    /**
     * A pickup application, belonging to the contact when there is one.
     */
    private function application(?Contact $contact = null): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            'contact_id' => $contact?->id,
        ])->save();

        return $application;
    }

    /**
     * A leaf application form submission, made $minutesAgo minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submission(string $reference, string $outcome, int $minutesAgo, array $attributes = []): FormSubmission
    {
        $submission = FormSubmission::create([
            'reference' => $reference,
            'form' => FormSubmission::FORM_LEAF_APPLICATION,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'data' => ['type' => 'pickup', 'estimated_bag_count' => '12', 'phone' => '(970) 555-0123'],
            'outcome' => $outcome,
            ...$attributes,
        ]);

        $submission->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $submission;
    }

    public function test_marking_an_application_hides_it_and_records_who_marked_it(): void
    {
        $application = $this->application();

        $application->flag($this->admin, LeafApplication::FLAG_SPAM, 'Someone probing the form.');

        $this->assertSoftDeleted($application);
        $this->assertSame(0, LeafApplication::count());

        $marked = LeafApplication::withTrashed()->find($application->id);
        $this->assertTrue($marked->isFlagged());
        $this->assertSame(LeafApplication::FLAG_SPAM, $marked->flagged_as);
        $this->assertTrue($marked->flagged_at->equalTo(now()));
        $this->assertTrue($marked->flaggedBy->is($this->admin));
        $this->assertSame('Someone probing the form.', $marked->flag_note);
        $this->assertSame([], LeafApplication::withTrashed()->notFlagged()->pluck('id')->all());
    }

    public function test_marking_an_application_closes_the_submissions_error_logs_links_to_it(): void
    {
        $application = $this->application();

        // Earlier tries by the same visitor, matched by email or phone number
        $byEmail = $this->submission('EMAIL2', FormSubmission::OUTCOME_REJECTED, 30, ['data' => ['type' => 'pickup']]);
        $byPhone = $this->submission('PHONE2', FormSubmission::OUTCOME_REJECTED, 20, ['email' => null]);
        // A try with nothing to match on, and one an admin already closed
        $unlinked = $this->submission('NOLINK', FormSubmission::OUTCOME_REJECTED, 15, ['name' => 'Q', 'email' => null, 'data' => ['type' => 'pickup']]);
        $closed = $this->submission('CLOSED', FormSubmission::OUTCOME_REJECTED, 12);
        $closed->close($this->admin, FormSubmission::RESOLUTION_RESOLVED, 'Called them back.');
        // The submission that saved the application
        $saved = $this->submission('SAVED2', FormSubmission::OUTCOME_SAVED, 10, ['leaf_application_id' => $application->id]);

        $application->flag($this->admin, LeafApplication::FLAG_SPAM);

        foreach ([$byEmail, $byPhone, $saved] as $submission) {
            $submission->refresh();
            $this->assertSame(FormSubmission::RESOLUTION_SPAM, $submission->resolution, $submission->reference);
            $this->assertSame($this->admin->id, $submission->resolved_by);
            $this->assertSame("Application {$application->id} was marked as spam.", $submission->resolution_note);
        }

        $this->assertNull($unlinked->refresh()->resolution);
        $this->assertSame(FormSubmission::RESOLUTION_RESOLVED, $closed->refresh()->resolution);
        $this->assertSame('Called them back.', $closed->resolution_note);

        // Error Logs leaves them out with the other spam
        $this->actingAs($this->admin)
            ->get('/admin/errors?period=all')
            ->assertOk()
            ->assertSee('NOLINK')
            ->assertDontSee('EMAIL2')
            ->assertDontSee('PHONE2');
    }

    public function test_marking_an_application_as_a_test_closes_its_submissions_as_tests(): void
    {
        $application = $this->application();
        $saved = $this->submission('SAVED2', FormSubmission::OUTCOME_SAVED, 10, ['leaf_application_id' => $application->id]);

        $application->flag($this->admin, LeafApplication::FLAG_TEST);

        $this->assertSame(LeafApplication::FLAG_TEST, $application->refresh()->flagged_as);
        $this->assertSame(FormSubmission::RESOLUTION_TEST, $saved->refresh()->resolution);
        $this->assertSame("Application {$application->id} was marked as a test.", $saved->resolution_note);
    }

    public function test_unmarking_restores_the_application_and_its_deleted_contact(): void
    {
        $contact = $this->contact();
        $application = $this->application($contact);
        $saved = $this->submission('SAVED2', FormSubmission::OUTCOME_SAVED, 10, ['leaf_application_id' => $application->id]);

        $application->flag($this->admin, LeafApplication::FLAG_SPAM, 'Oops.');
        $contact->delete();

        LeafApplication::withTrashed()->find($application->id)->unflag();

        $application->refresh();
        $this->assertNotSoftDeleted($application);
        $this->assertFalse($application->isFlagged());
        $this->assertNull($application->flagged_at);
        $this->assertNull($application->flagged_by);
        $this->assertNull($application->flag_note);
        $this->assertNotSoftDeleted($contact);

        // Its submissions stay closed, to reopen on Error Logs
        $this->assertSame(FormSubmission::RESOLUTION_SPAM, $saved->refresh()->resolution);
    }

    public function test_unmarking_leaves_a_merged_contact_deleted(): void
    {
        $other = Contact::create(['name' => 'Jamie R.']);
        $contact = $this->contact();
        $application = $this->application($contact);

        $application->flag($this->admin, LeafApplication::FLAG_SPAM);
        $contact->forceFill(['merged_into_id' => $other->id])->save();
        $contact->delete();

        LeafApplication::withTrashed()->find($application->id)->unflag();

        $this->assertSoftDeleted($contact);
    }

    public function test_a_contact_whose_applications_are_all_marked_can_be_deleted(): void
    {
        $contact = $this->contact();
        $first = $this->application($contact);
        $second = $this->application($contact);

        // One real application still keeps the contact
        $first->flag($this->admin, LeafApplication::FLAG_SPAM);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertOk()
            ->assertSee('Jamie Rivera has an application, so they can’t be deleted.')
            ->assertDontSee('action="' . route('admin.contacts.destroy', $contact->id) . '"', false);

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])
            ->assertRedirect(route('admin.contacts.edit', $contact->id) . '#delete')
            ->assertSessionHasErrors(['confirm' => 'Jamie Rivera has an application, so they can’t be deleted. If this is a duplicate, merge it into the other contact from Shares Details With.']);

        $this->assertNotSoftDeleted($contact);

        // Once every application is marked, it can be deleted
        $second->flag($this->admin, LeafApplication::FLAG_TEST);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertOk()
            ->assertSee('action="' . route('admin.contacts.destroy', $contact->id) . '"', false)
            ->assertDontSee('so they can’t be deleted');

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])
            ->assertRedirect(route('admin.contacts'))
            ->assertSessionHas('status', 'Deleted Jamie Rivera.');

        $this->assertSoftDeleted($contact);
    }
}
