<?php

namespace App\Reports\Traits;

use App\Models\Checkout;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Reports\FormSubmissionReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * How reports describe a form submission, so submissions:list, its CSV, and
 * the admin Error Logs pages show the same details. Query submissions
 * through FormSubmissionReport, which loads their checkouts and later
 * submissions.
 */
trait DescribesFormSubmissions
{
    use FormatsTimes;

    /**
     * What became of a submission that didn't go through, from
     * formSubmissionFollowUp(): the visitor submitted the same form
     * successfully later (resolved), never did (unresolved), or an admin
     * marked it as spam.
     */
    protected const FOLLOW_UP_RESOLVED = 'resolved';

    protected const FOLLOW_UP_UNRESOLVED = 'unresolved';

    protected const FOLLOW_UP_SPAM = 'spam';

    /**
     * Labels for a submission's fields, in order.
     */
    protected const FORM_SUBMISSION_LABELS = ['Form', 'What happened', 'Follow-up', 'Name', 'Email', 'Details', 'Errors shown', 'Browser'];

    /**
     * The columns of a list of submissions: the key details of each.
     */
    protected const FORM_SUBMISSION_TABLE_HEADERS = ['Reference', 'Submitted', 'Form', 'What happened', 'Name', 'Follow-up'];

    /**
     * A submission's row in a list, matching FORM_SUBMISSION_TABLE_HEADERS.
     * Without a name, the email address stands in for it.
     *
     * @return array<int, string>
     */
    protected function formSubmissionTableRow(FormSubmission $submission): array
    {
        $fields = $this->formSubmissionFields($submission);

        return [
            $submission->reference,
            $this->tableTime($submission->created_at),
            $fields['Form'],
            $fields['What happened'],
            $fields['Name'] ?: $fields['Email'],
            $this->formSubmissionFollowUpText($submission),
        ];
    }

    /**
     * The header row of a CSV of submissions: the reference, when it was
     * submitted, and every detail.
     *
     * @return array<int, string>
     */
    protected function formSubmissionCsvHeader(): array
    {
        return ['Reference', 'Submitted', ...self::FORM_SUBMISSION_LABELS];
    }

    /**
     * A submission's row in a CSV, matching formSubmissionCsvHeader().
     *
     * @return array<int, string>
     */
    protected function formSubmissionCsvRow(FormSubmission $submission): array
    {
        return [
            $submission->reference,
            $this->localTime($submission->created_at),
            ...array_values($this->formSubmissionFields($submission)),
        ];
    }

    /**
     * What a list of submissions holds: "submissions that did not succeed",
     * or every submission with $all.
     */
    protected function formSubmissionListName(bool $all, int $count): string
    {
        return Str::plural('submission', $count) . ($all ? '' : ' that did not succeed');
    }

    /**
     * A submission's fields, keyed by FORM_SUBMISSION_LABELS. The follow-up
     * names the later submission's reference, since a CSV or the command
     * can't link to it.
     *
     * @return array<string, string>
     */
    protected function formSubmissionFields(FormSubmission $submission): array
    {
        return array_combine(self::FORM_SUBMISSION_LABELS, [
            $submission->form === FormSubmission::FORM_DONATION ? 'Donation' : 'Leaf application',
            $this->formSubmissionOutcome($submission),
            $this->formSubmissionFollowUpText($submission, withReference: true),
            (string) $submission->name,
            (string) $submission->email,
            $this->formSubmissionDetails($submission),
            collect($submission->errors ?? [])->flatten()->unique()->implode(' '),
            (string) $submission->user_agent,
        ]);
    }

    /**
     * What became of the submission: one of the FOLLOW_UP_ values, or null
     * for one that went through or is still in progress.
     */
    protected function formSubmissionFollowUp(FormSubmission $submission): ?string
    {
        return match (true) {
            $submission->isSpam() => self::FOLLOW_UP_SPAM,
            $submission->laterSubmission !== null => self::FOLLOW_UP_RESOLVED,
            (new FormSubmissionReport)->needsAttention($submission) => self::FOLLOW_UP_UNRESOLVED,
            default => null,
        };
    }

    /**
     * The follow-up in plain words, such as "Submitted 4 minutes later" or
     * "No record", with the later submission's reference if $withReference.
     * Empty for a submission that went through.
     */
    protected function formSubmissionFollowUpText(FormSubmission $submission, bool $withReference = false): string
    {
        $later = $submission->laterSubmission;

        return match ($this->formSubmissionFollowUp($submission)) {
            self::FOLLOW_UP_SPAM => 'Marked as spam',
            self::FOLLOW_UP_RESOLVED => 'Submitted '
                . $later->created_at->diffForHumans($submission->created_at, CarbonInterface::DIFF_ABSOLUTE)
                . ' later' . ($withReference ? " as {$later->reference}" : ''),
            self::FOLLOW_UP_UNRESOLVED => 'No record',
            default => '',
        };
    }

    /**
     * What happened to the submission, in plain words.
     */
    protected function formSubmissionOutcome(FormSubmission $submission): string
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
    protected function formSubmissionDetails(FormSubmission $submission): string
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
}
