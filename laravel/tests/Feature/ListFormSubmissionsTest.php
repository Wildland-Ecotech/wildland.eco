<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan submissions:list`: the form submissions that need a follow-up.
 */
class ListFormSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A donation submission, created $minutesAgo minutes ago.
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

    public function test_only_submissions_that_did_not_succeed_are_listed(): void
    {
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED, ['errors' => ['email' => ['Enter a valid email address, such as name@example.com.']]]);
        $this->submission('ERR333', FormSubmission::OUTCOME_ERROR);
        $this->submission('STUCK4', FormSubmission::OUTCOME_RECEIVED);
        $this->submission('NOW555', FormSubmission::OUTCOME_RECEIVED, minutesAgo: 1);
        $this->submission('PAID66', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->create(['status' => Checkout::STATUS_COMPLETED])->id,
        ]);
        $this->submission('LEFT77', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->create(['status' => Checkout::STATUS_EXPIRED])->id,
        ]);

        $this->artisan('submissions:list')
            ->expectsOutputToContain('REJ222')
            ->expectsOutputToContain('Rejected by the form')
            ->expectsOutputToContain('Enter a valid email address')
            ->expectsOutputToContain('$20, monthly')
            ->expectsOutputToContain('ERR333')
            ->expectsOutputToContain('STUCK4')
            ->expectsOutputToContain('LEFT77')
            ->expectsOutputToContain('Left the payment page without paying')
            ->doesntExpectOutputToContain('PAID66')
            // Still in progress, not stuck
            ->doesntExpectOutputToContain('NOW555')
            ->expectsOutputToContain('4 submissions that did not succeed')
            ->assertSuccessful();
    }

    public function test_all_includes_submissions_that_succeeded(): void
    {
        $this->submission('PAID66', FormSubmission::OUTCOME_SENT_TO_PAYMENT, [
            'checkout_id' => Checkout::factory()->create(['status' => Checkout::STATUS_COMPLETED])->id,
        ]);

        $this->artisan('submissions:list', ['--all' => true])
            ->expectsOutputToContain('PAID66')
            ->expectsOutputToContain('Paid')
            ->assertSuccessful();
    }

    public function test_days_and_form_narrow_the_list(): void
    {
        $this->submission('OLD222', FormSubmission::OUTCOME_REJECTED, minutesAgo: 60 * 24 * 10);
        $this->submission('LEAF33', FormSubmission::OUTCOME_REJECTED, [
            'form' => FormSubmission::FORM_LEAF_APPLICATION,
            'data' => ['type' => 'pickup', 'estimated_bag_count' => '12'],
        ]);

        $this->artisan('submissions:list', ['--form' => FormSubmission::FORM_DONATION])
            ->expectsOutputToContain('No submissions that did not succeed in the last 7 days.')
            ->assertSuccessful();

        $this->artisan('submissions:list', ['--days' => 30])
            ->expectsOutputToContain('OLD222')
            ->expectsOutputToContain('LEAF33')
            ->expectsOutputToContain('Pickup, 12 bags')
            ->assertSuccessful();
    }

    public function test_csv_output_is_safe_to_open_in_a_spreadsheet(): void
    {
        $this->submission('CSV222', FormSubmission::OUTCOME_REJECTED, ['name' => '=HYPERLINK("https://example.com")']);

        // The CSV is written in one piece, so check the whole output
        $this->assertSame(0, Artisan::call('submissions:list', ['--csv' => true]));

        $csv = Artisan::output();

        $this->assertStringStartsWith('Reference,Submitted,Form,', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""https://example.com"")"', $csv);
    }

    public function test_options_are_checked(): void
    {
        $this->artisan('submissions:list', ['--days' => '0'])->assertFailed();
        $this->artisan('submissions:list', ['--form' => 'newsletter'])->assertFailed();
    }
}
