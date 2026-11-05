<?php

namespace Tests\Feature;

use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Error Logs pages: /admin/errors, its CSV download, and one
 * submission in full by its reference.
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

        $this->admin = User::factory()->admin()->withPassword()->create();
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

    public function test_only_signed_in_admins_can_see_error_logs(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $pages = ['/admin/errors', '/admin/errors/csv', '/admin/errors/REJ222'];

        // Every page as a guest first, since signing in lasts for the rest of the test
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }
    }

    public function test_lists_submissions_that_did_not_succeed_with_a_link_to_each(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('SAV333', FormSubmission::OUTCOME_SAVED);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertOk()
            ->assertSee('1 submission that did not succeed in the last 30 days.')
            ->assertSee('REJ222')
            ->assertSee('Rejected by the form')
            ->assertSee('Oct 21, 2026 6:30 PM MDT')
            ->assertSee('href="' . route('admin.errors.show', 'REJ222') . '"', false)
            ->assertSee('<summary>Oct 21 · Jamie Rivera · Rejected by the form</summary>', false)
            ->assertSee('aria-current="page">Error Logs</a>', false)
            ->assertDontSee('SAV333');
    }

    public function test_the_email_address_stands_in_for_a_missing_name(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['name' => null]);

        $this->actingAs($this->admin)
            ->get('/admin/errors')
            ->assertSee('<summary>Oct 21 · jamie@example.com · Rejected by the form</summary>', false);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('LEAF44', FormSubmission::OUTCOME_ERROR, ['form' => FormSubmission::FORM_LEAF_APPLICATION, 'data' => ['type' => 'pickup']]);
        $this->submission('SAV333', FormSubmission::OUTCOME_SAVED);
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

    public function test_the_csv_download_has_every_detail(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['name' => '=HYPERLINK("https://example.com")']);

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

        $this->assertSame(['Reference', 'Submitted', 'Form', 'What happened', 'Name', 'Email', 'Details', 'Errors shown', 'Browser'], $header);
        $this->assertSame('REJ222', $row[0]);
        // Kept from running as a formula in a spreadsheet
        $this->assertSame('\'=HYPERLINK("https://example.com")', $row[4]);
        $this->assertSame('$20, monthly', $row[6]);
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
                ->assertSee('jamie@example.com')
                ->assertSee('$20, monthly')
                ->assertSee('Enter a valid email address, such as name@example.com.')
                ->assertSee('Mozilla/5.0 (Example)');
        }
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/errors/ZZZ999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/errors/ABC')->assertNotFound();
    }
}
