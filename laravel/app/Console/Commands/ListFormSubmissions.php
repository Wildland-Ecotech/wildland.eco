<?php

namespace App\Console\Commands;

use App\Models\Checkout;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists recent submissions of the donate and leaf application forms that
 * didn't succeed, so we can reach out to the people who made them: rejected,
 * expired, rate-limited, and failed submissions, submissions that stopped
 * without an outcome, and donors who went to pay but left the payment page
 * without finishing. The output contains visitors' names, emails, and form
 * details: never paste it into issues, chat, or the repository.
 */
class ListFormSubmissions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'submissions:list
        {--days=7 : Show submissions from this many days back}
        {--form= : Only one form: donation or leaf_application}
        {--all : Include submissions that succeeded}
        {--csv : Output CSV, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List recent form submissions that did not succeed';

    /**
     * Time zone for submission times, matching the rest of the site's dates.
     */
    private const TIMEZONE = 'America/Denver';

    /**
     * A submission still marked received this long after it arrived stopped
     * without an outcome; newer ones may still be in progress.
     */
    private const IN_PROGRESS_MINUTES = 5;

    /**
     * Labels for the fields shown for each submission, in order.
     */
    private const LABELS = ['Form', 'What happened', 'Name', 'Email', 'Details', 'Errors shown', 'Browser'];

    public function handle(): int
    {
        $days = $this->option('days');
        $form = $this->option('form');

        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->components->error('--days must be a whole number of at least 1.');

            return self::INVALID;
        }

        if ($form !== null && ! in_array($form, FormSubmission::FORMS, true)) {
            $this->components->error('--form must be one of: ' . implode(', ', FormSubmission::FORMS) . '.');

            return self::INVALID;
        }

        $submissions = FormSubmission::query()
            ->with('checkout')
            ->where('created_at', '>=', now()->subDays((int) $days))
            ->when($form, fn ($query) => $query->where('form', $form))
            ->orderBy('created_at')
            ->get()
            ->when(! $this->option('all'), fn (Collection $submissions) => $submissions->filter($this->needsAttention(...)))
            ->values();

        if ($this->option('csv')) {
            $this->output->write($this->csv($submissions), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $which = $this->option('all') ? 'submissions' : 'submissions that did not succeed';

        if ($submissions->isEmpty()) {
            $this->components->info("No {$which} in the last {$days} days.");

            return self::SUCCESS;
        }

        foreach ($submissions as $submission) {
            $this->newLine();
            $this->line("<options=bold>{$submission->reference}</> " . $this->submittedAt($submission));

            foreach ($this->fields($submission) as $label => $value) {
                // Escaped so submitted text cannot add console formatting tags
                $this->line("  <fg=gray>{$label}:</> " . ($value === '' ? '—' : OutputFormatter::escape($value)));
            }
        }

        $this->newLine();
        $count = $submissions->count();
        $this->components->info("{$count} " . Str::plural('submission', $count) . ($this->option('all') ? '' : ' that did not succeed') . " in the last {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Whether a submission didn't get the visitor what they came for.
     */
    private function needsAttention(FormSubmission $submission): bool
    {
        return $submission->failed()
            || $this->abandonedPayment($submission)
            || ($submission->outcome === FormSubmission::OUTCOME_RECEIVED
                && $submission->created_at->lt(now()->subMinutes(self::IN_PROGRESS_MINUTES)));
    }

    /**
     * Whether the donor went to pay but left without finishing, so their
     * checkout expired.
     */
    private function abandonedPayment(FormSubmission $submission): bool
    {
        return $submission->outcome === FormSubmission::OUTCOME_SENT_TO_PAYMENT
            && $submission->checkout?->status === Checkout::STATUS_EXPIRED;
    }

    /**
     * A submission's fields, keyed by LABELS.
     *
     * @return array<string, string>
     */
    private function fields(FormSubmission $submission): array
    {
        return array_combine(self::LABELS, [
            $submission->form === FormSubmission::FORM_DONATION ? 'Donation' : 'Leaf application',
            $this->outcome($submission),
            (string) $submission->name,
            (string) $submission->email,
            $this->details($submission),
            collect($submission->errors ?? [])->flatten()->unique()->implode(' '),
            (string) $submission->user_agent,
        ]);
    }

    /**
     * What happened to the submission, in plain words.
     */
    private function outcome(FormSubmission $submission): string
    {
        return match ($submission->outcome) {
            FormSubmission::OUTCOME_RECEIVED => 'Stopped without an outcome (check the Laravel log)',
            FormSubmission::OUTCOME_REJECTED => 'Rejected by the form',
            FormSubmission::OUTCOME_EXPIRED => 'The page had expired',
            FormSubmission::OUTCOME_RATE_LIMITED => 'Too many attempts in an hour',
            FormSubmission::OUTCOME_UNAVAILABLE => 'Online donations were unavailable',
            FormSubmission::OUTCOME_PAYMENT_ERROR => 'Could not reach the payment processor',
            FormSubmission::OUTCOME_ERROR => 'Server error (check the Laravel log)',
            FormSubmission::OUTCOME_SENT_TO_PAYMENT => match ($submission->checkout?->status) {
                Checkout::STATUS_COMPLETED => 'Paid',
                Checkout::STATUS_EXPIRED => 'Left the payment page without paying',
                default => 'Sent to the payment page',
            },
            FormSubmission::OUTCOME_SAVED => 'Saved',
            default => $submission->outcome,
        };
    }

    /**
     * The main details entered, depending on the form.
     */
    private function details(FormSubmission $submission): string
    {
        $data = $submission->data ?? [];

        if ($submission->form === FormSubmission::FORM_DONATION) {
            $frequency = $data['frequency'] ?? '';
            $amount = $data['custom_amount'] ?? $data["amount_{$frequency}"] ?? '';

            return trim(($amount === '' ? 'No amount' : '$' . ltrim($amount, '$')) . ', ' . match ($frequency) {
                Checkout::FREQUENCY_ONE_TIME => 'one-time',
                Checkout::FREQUENCY_MONTHLY => 'monthly',
                Checkout::FREQUENCY_ANNUAL => 'annual',
                default => 'no frequency',
            });
        }

        return collect([
            match ($data['type'] ?? null) {
                LeafApplication::TYPE_PICKUP => 'Pickup',
                LeafApplication::TYPE_DROP_OFF => 'Drop-off',
                default => null,
            },
            isset($data['estimated_bag_count']) ? "{$data['estimated_bag_count']} bags" : null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
        ])->filter()->implode(', ');
    }

    /**
     * When the submission arrived, in Fort Collins time.
     */
    private function submittedAt(FormSubmission $submission): string
    {
        return $submission->created_at->setTimezone(self::TIMEZONE)->format('D, M j, Y g:i A T');
    }

    /**
     * All submissions as CSV, one row each, oldest first.
     *
     * @param  Collection<int, FormSubmission>  $submissions
     */
    private function csv(Collection $submissions): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Reference', 'Submitted', ...self::LABELS], escape: '');

        foreach ($submissions as $submission) {
            $row = [$submission->reference, $this->submittedAt($submission), ...array_values($this->fields($submission))];

            fputcsv($handle, array_map($this->spreadsheetSafe(...), $row), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Anyone can submit the forms, so a value starting with =, +, -, or @
     * could run as a formula when the CSV is opened in a spreadsheet. A
     * leading apostrophe makes spreadsheets treat it as text.
     */
    private function spreadsheetSafe(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }
}
