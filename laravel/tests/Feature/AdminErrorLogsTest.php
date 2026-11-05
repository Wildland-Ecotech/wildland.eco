<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Error Logs pages: /admin/errors, its CSV download, one
 * submission in full by its reference, whether each visitor got through
 * later, and closing and reopening errors.
 */
class AdminErrorLogsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
    }

    /**
     * A donation form submission, made $minutesAgo minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submission(string $reference, string $outcome, array $attributes = [], int $minutesAgo = 60): FormSubmission
    {
        $submission = FormSubmission::create([
            'reference' => $reference,
            'form' => FormSubmission::FORM_DONATION,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'data' => ['frequency' => 'monthly', 'amount_monthly' => '20'],
            'outcome' => $outcome,
            ...$attributes,
        ]);

        $submission->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $submission;
    }

    /**
     * A leaf application form submission, made $minutesAgo minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function leafSubmission(string $reference, string $outcome, array $attributes = [], int $minutesAgo = 60): FormSubmission
    {
        return $this->submission($reference, $outcome, [
            'form' => FormSubmission::FORM_LEAF_APPLICATION,
            'data' => ['type' => 'pickup', 'estimated_bag_count' => '12', 'phone' => '(970) 555-0123'],
            ...$attributes,
        ], $minutesAgo);
    }

    private function application(): LeafApplication
    {
        return LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'terms_accepted_at' => now(),
        ]);
    }

    public function test_only_signed_in_admins_can_see_error_logs(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $pages = ['/admin/errors', '/admin/errors/csv', '/admin/errors/REJ222'];

        // Every page as a guest first, since signing in lasts for the rest of the test
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $close = ['references' => ['REJ222'], 'resolution' => FormSubmission::RESOLUTION_SPAM];
        $this->post('/admin/errors/close', $close)->assertRedirect(route('login'));
        $this->post('/admin/errors/reopen', $close)->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }

        $this->actingAs($donor)->post('/admin/errors/close', $close)->assertForbidden();
        $this->actingAs($donor)->post('/admin/errors/reopen', $close)->assertForbidden();
        $this->assertFalse($this->submissionNamed('REJ222')->isClosed());
    }

    public function test_lists_submissions_that_did_not_succeed_with_a_link_to_each(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('SAV333', FormSubmission::OUTCOME_SAVED, ['email' => 'alex@example.com']);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertOk()
            ->assertSee('1 submission that did not succeed in the last 30 days.')
            ->assertSee('REJ222')
            ->assertSee('Rejected by the form')
            ->assertSee('Oct 21, 6:30 PM')
            // The reference is the link, rather than a separate View column
            ->assertSee('<a class="record-link" href="' . route('admin.errors.show', 'REJ222') . '">REJ222</a>', false)
            ->assertDontSee('>View</a>', false)
            ->assertSee('<summary><span class="status-icon failure" aria-hidden="true">✕</span><span class="visually-hidden">No record: </span>Oct 21 · Jamie Rivera · Rejected by the form</summary>', false)
            ->assertSee('<span class="status-icon failure" aria-hidden="true">✕</span>No record</span>', false)
            ->assertSee('aria-current="page">Error Logs</a>', false)
            ->assertDontSee('SAV333');
    }

    public function test_the_email_address_stands_in_for_a_missing_name(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['name' => null]);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('No record: </span>Oct 21 · jamie@example.com · Rejected by the form</summary>', false);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('LEAF44', FormSubmission::OUTCOME_ERROR, ['form' => FormSubmission::FORM_LEAF_APPLICATION, 'data' => ['type' => 'pickup']]);
        $this->submission('SAV333', FormSubmission::OUTCOME_SAVED, ['email' => 'alex@example.com']);
        $this->submission('OLD555', FormSubmission::OUTCOME_REJECTED, minutesAgo: 60 * 24 * 45);

        $this->actingAs($this->admin)
            ->get('/admin/errors?form[]=leaf_application')
            ->assertSee('LEAF44')
            ->assertDontSee('REJ222')
            ->assertSee('1 submission that did not succeed in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/errors?all=1')
            ->assertSee('SAV333')
            ->assertSee('3 submissions in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/errors?period=90')
            ->assertSee('OLD555')
            ->assertSee('3 submissions that did not succeed in the last 90 days.');
    }

    public function test_says_when_there_are_no_errors(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertOk()
            ->assertSee('No submissions that did not succeed in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/errors?period=all')
            ->assertSee('No submissions that did not succeed yet.');
    }

    public function test_a_visitor_who_applied_later_links_to_their_application(): void
    {
        $this->leafSubmission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $application = $this->application();
        $this->leafSubmission('SAV333', FormSubmission::OUTCOME_SAVED, ['leaf_application_id' => $application->id], minutesAgo: 56);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<summary><span class="status-icon success" aria-hidden="true">✓</span><span class="visually-hidden">Submitted later: </span>Oct 21 · Jamie Rivera · Rejected by the form</summary>', false)
            ->assertSee('<a href="' . route('admin.leaves.show', $application) . '">Submitted 4 minutes later</a>', false);

        // Left out of the list of unresolved errors, as the dashboard links to it
        $this->actingAs($this->admin)
            ->get('/admin/errors?later=0')
            ->assertDontSee('REJ222')
            ->assertSee('No submissions that did not succeed in the last 30 days.')
            ->assertSee('<input type="checkbox" name="later" value="1" >', false);

        // Checking Submitted later sends both values, and the checkbox's comes last
        $this->actingAs($this->admin)
            ->get('/admin/errors?later=0&later=1')
            ->assertSee('REJ222');

        $this->actingAs($this->admin)
            ->get('/admin/errors/REJ222')
            ->assertSee('<a href="' . route('admin.leaves.show', $application) . '">Submitted 4 minutes later</a>', false);
    }

    public function test_leaf_applications_also_match_by_phone_number(): void
    {
        // A mistyped email address, then the right one with the phone number spelled differently
        $this->leafSubmission('REJ222', FormSubmission::OUTCOME_REJECTED, ['email' => 'jamie@exmaple.com'], minutesAgo: 60 * 24 * 2);
        $this->leafSubmission('SAV333', FormSubmission::OUTCOME_SAVED, [
            'data' => ['type' => 'pickup', 'phone' => '+1 970.555.0123'],
            'leaf_application_id' => $this->application()->id,
        ], minutesAgo: 60 * 23);

        // Donations have no phone number, so a different email address is someone else
        $this->submission('REJ444', FormSubmission::OUTCOME_REJECTED, ['email' => 'jamie@exmaple.com']);
        $this->submission('PAID55', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->completed()->create()->id,
        ], minutesAgo: 30);

        $response = $this->actingAs($this->admin)->get('/admin/errors')->assertOk();

        $response->assertSee('Submitted 1 day later');
        // Only REJ444, in both the table and its phone line
        $this->assertSame(2, substr_count($response->getContent(), '✕</span>No record</span>'));
    }

    public function test_a_donor_who_paid_later_links_to_their_donation(): void
    {
        $this->submission('LEFT22', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->expired()->create()->id,
        ], minutesAgo: 180);
        // A second try that wasn't paid either doesn't resolve the first
        $this->submission('STRT33', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->expired()->create()->id,
        ], minutesAgo: 120);
        $checkout = Checkout::factory()->completed()->create();
        $donation = Donation::factory()->create(['checkout_id' => $checkout->id]);
        $this->submission('PAID44', FormSubmission::OUTCOME_SENT_TO_PAYMENT, ['checkout_id' => $checkout->id], minutesAgo: 60);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<a href="' . route('admin.donations.show', $donation->id) . '">Submitted 2 hours later</a>', false)
            ->assertSee('<a href="' . route('admin.donations.show', $donation->id) . '">Submitted 1 hour later</a>', false)
            ->assertDontSee('PAID44');
    }

    public function test_only_later_submissions_of_the_same_form_count(): void
    {
        // Applied before the error, and donated after it: neither resolves it
        $this->leafSubmission('SAV222', FormSubmission::OUTCOME_SAVED, ['leaf_application_id' => $this->application()->id], minutesAgo: 90);
        $this->leafSubmission('REJ333', FormSubmission::OUTCOME_REJECTED);
        $this->submission('PAID44', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->completed()->create()->id,
        ], minutesAgo: 30);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('REJ333')
            ->assertSee('✕</span>No record</span>', false)
            ->assertDontSee('later</a>', false);
    }

    public function test_the_csv_download_has_every_detail(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['name' => '=HYPERLINK("https://example.com")']);
        $this->submission('REJ333', FormSubmission::OUTCOME_REJECTED, ['email' => 'alex@example.com'], minutesAgo: 30);
        $this->submission('PAID44', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->completed()->create()->id,
        ], minutesAgo: 20);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.errors.csv', ['form' => ['donation']]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('error-logs-2026-10-21.csv');

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->getContent());
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        $row = fgetcsv($handle, escape: '');
        fclose($handle);

        $this->assertSame(['Reference', 'Submitted', 'Form', 'What happened', 'Follow-up', 'Name', 'Email', 'Details', 'Errors shown', 'Browser'], $header);
        $this->assertSame('REJ222', $row[0]);
        // Names the later submission, which a CSV can't link to
        $this->assertSame('Submitted 40 minutes later as PAID44', $row[4]);
        // Kept from running as a formula in a spreadsheet
        $this->assertSame('\'=HYPERLINK("https://example.com")', $row[5]);
        $this->assertSame('$20, monthly', $row[7]);

        // Without submissions the visitor got through with later, only the later error is left
        $csv = $this->actingAs($this->admin)->get(route('admin.errors.csv', ['later' => '0']))->getContent();

        $this->assertStringNotContainsString('REJ222', $csv);
        $this->assertStringContainsString('REJ333', $csv);
    }

    public function test_shows_one_submission_in_full_by_its_reference(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, [
            'errors' => ['email' => ['Enter a valid email address, such as name@example.com.']],
            'user_agent' => 'Mozilla/5.0 (Example)',
        ]);

        foreach (['/admin/errors/REJ222', '/admin/errors/rej222'] as $page) {
            $this->actingAs($this->admin)
                ->get($page)
                ->assertOk()
                ->assertSee('Submission REJ222')
                ->assertSee('Wed, Oct 21, 2026 6:30 PM MDT')
                ->assertSee('Rejected by the form')
                ->assertSee('<span class="status-icon failure" aria-hidden="true">✕</span>No record</span>', false)
                ->assertSee('jamie@example.com')
                ->assertSee('$20, monthly')
                ->assertSee('Enter a valid email address, such as name@example.com.')
                ->assertSee('Mozilla/5.0 (Example)')
                ->assertSee('Close this error')
                ->assertSee('<input type="radio" name="resolution" value="resolved" checked>', false);
        }
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/errors/ZZZ999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/errors/ABC')->assertNotFound();
    }

    public function test_admins_close_errors_from_the_list(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('ERR333', FormSubmission::OUTCOME_ERROR);
        $this->submission('REJ444', FormSubmission::OUTCOME_REJECTED);
        $this->submission('EXP555', FormSubmission::OUTCOME_EXPIRED);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<input type="checkbox" form="close-errors" name="references[]" value="REJ222" aria-label="Select REJ222">', false)
            ->assertSee('<form id="close-errors" class="bulk-actions" method="POST" action="' . route('admin.errors.close') . '">', false)
            ->assertSee('<option value="spam" >Spam</option>', false);

        // Each row's checkbox is in both the table and the phone list; lowercase is found too
        $this->actingAs($this->admin)
            ->from('/admin/errors?period=90')
            ->post('/admin/errors/close', ['references' => ['REJ222', 'rej222', 'ERR333', 'ZZZ999'], 'resolution' => FormSubmission::RESOLUTION_SPAM])
            ->assertRedirect('/admin/errors?period=90')
            ->assertSessionHas('status', '2 submissions marked as spam.');

        $this->actingAs($this->admin)
            ->post('/admin/errors/close', ['references' => ['REJ444'], 'resolution' => FormSubmission::RESOLUTION_TEST])
            ->assertSessionHas('status', '1 submission marked as a test.');

        $this->actingAs($this->admin)
            ->post('/admin/errors/close', ['references' => ['EXP555'], 'resolution' => FormSubmission::RESOLUTION_RESOLVED])
            ->assertSessionHas('status', '1 submission marked as resolved.');

        $spam = $this->submissionNamed('REJ222');
        $this->assertSame(FormSubmission::RESOLUTION_SPAM, $spam->resolution);
        $this->assertSame($this->admin->id, $spam->resolved_by);
        $this->assertNull($spam->resolution_note);

        // Resolved stays listed with a check; spam and tests are left out
        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('1 submission that did not succeed in the last 30 days.')
            ->assertSee('<span class="status-icon success" aria-hidden="true">✓</span>Resolved by Sam Rivera</span>', false)
            ->assertDontSee('REJ222')
            ->assertDontSee('REJ444');

        // Only unresolved errors, as the dashboard counts them
        $this->actingAs($this->admin)
            ->get('/admin/errors?later=0&resolved=0')
            ->assertSee('No submissions that did not succeed in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/errors?spam=1')
            ->assertSee('4 submissions that did not succeed in the last 30 days.')
            ->assertSee('<span class="status-icon muted" aria-hidden="true">–</span>Marked as spam</span>', false)
            ->assertSee('<span class="status-icon muted" aria-hidden="true">–</span>Marked as a test</span>', false);
    }

    public function test_closing_errors_needs_a_submission_and_a_resolution(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);

        $this->actingAs($this->admin)
            ->from('/admin/errors')
            ->post('/admin/errors/close', ['resolution' => 'ignored', 'note' => str_repeat('a', 1001)])
            ->assertRedirect('/admin/errors')
            ->assertSessionHasErrors([
                'references' => 'Select at least one submission to mark.',
                'resolution' => 'Choose resolved, test, or spam.',
                'note' => 'Keep the note to 1,000 characters or fewer.',
            ]);

        $this->assertFalse($this->submissionNamed('REJ222')->isClosed());

        // Shown on the list it went back to. Checked by following the redirect,
        // since reading the session above leaves the next request without errors
        $this->actingAs($this->admin)
            ->followingRedirects()
            ->from('/admin/errors')
            ->post('/admin/errors/close', ['resolution' => FormSubmission::RESOLUTION_SPAM])
            ->assertOk()
            ->assertSee('REJ222')
            ->assertSee('Select at least one submission to mark.');
    }

    public function test_an_error_is_closed_with_a_note_and_reopened_on_its_page(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['name' => null]);

        $this->actingAs($this->admin)
            ->from('/admin/errors/REJ222')
            ->post('/admin/errors/close', [
                'references' => ['REJ222'],
                'resolution' => FormSubmission::RESOLUTION_TEST,
                'note' => 'My own test donation.',
            ])
            ->assertRedirect('/admin/errors/REJ222');

        $this->actingAs($this->admin)
            ->get('/admin/errors/REJ222')
            ->assertSee('1 submission marked as a test.')
            ->assertSee('Marked as a test by Sam Rivera on Oct 21, 2026 7:30 PM MDT: “My own test donation.”')
            ->assertSee('<span class="status-icon muted" aria-hidden="true">–</span>Marked as a test</span>', false)
            ->assertSee('Reopen')
            ->assertDontSee('Close this error');

        // The CSV has the note
        $csv = $this->actingAs($this->admin)->get(route('admin.errors.csv', ['spam' => '1']))->getContent();
        $this->assertStringContainsString('Marked as a test: My own test donation.', $csv);

        $this->actingAs($this->admin)
            ->from('/admin/errors/REJ222')
            ->post('/admin/errors/reopen', ['references' => ['REJ222']])
            ->assertRedirect('/admin/errors/REJ222')
            ->assertSessionHas('status', '1 submission reopened.');

        $submission = $this->submissionNamed('REJ222');
        $this->assertFalse($submission->isClosed());
        $this->assertNull($submission->resolved_by);
        $this->assertNull($submission->resolution_note);
    }

    public function test_an_admins_closing_replaces_submitted_later(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, minutesAgo: 120);
        $this->submission('PAID33', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->completed()->create()->id,
        ], minutesAgo: 60);
        $this->submissionNamed('REJ222')->close($this->admin, FormSubmission::RESOLUTION_RESOLVED);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<span class="status-icon success" aria-hidden="true">✓</span>Resolved by Sam Rivera</span>', false)
            ->assertDontSee('Submitted 1 hour later');

        // Left out of the unresolved list either way
        $this->actingAs($this->admin)
            ->get('/admin/errors?later=0&resolved=0')
            ->assertDontSee('REJ222');
    }

    private function submissionNamed(string $reference): FormSubmission
    {
        return FormSubmission::where('reference', $reference)->firstOrFail();
    }
}
