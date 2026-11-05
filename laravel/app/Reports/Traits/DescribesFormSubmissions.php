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
     * can't link to it, and the admin's note.
     *
     * @return array<string, string>
     */
    protected function formSubmissionFields(FormSubmission $submission): array
    {
        return array_combine(self::FORM_SUBMISSION_LABELS, [
            $submission->form === FormSubmission::FORM_DONATION ? 'Donation' : 'Leaf application',
            $this->formSubmissionOutcome($submission),
            $this->formSubmissionFollowUpText($submission, detailed: true),
            (string) $submission->name,
            (string) $submission->email,
            $this->formSubmissionDetails($submission),
            collect($submission->errors ?? [])->flatten()->unique()->implode(' '),
            (string) $submission->user_agent,
        ]);
    }

    /**
     * Where the submission stands: one of FormSubmissionReport's FOLLOW_UP_
     * values, or null for one that went through or is still in progress.
     */
    protected function formSubmissionFollowUp(FormSubmission $submission): ?string
    {
        return (new FormSubmissionReport)->followUp($submission);
    }

    /**
     * The follow-up in plain words, such as "Submitted 4 minutes later",
     * "Resolved by Rachel Shelton", or "No record". $detailed adds the later
     * submission's reference, or the note an admin left when closing it.
     * Empty for a submission that went through.
     */
    protected function formSubmissionFollowUpText(FormSubmission $submission, bool $detailed = false): string
    {
        $later = $submission->laterSubmission;
        $note = $detailed && $submission->resolution_note ? ": {$submission->resolution_note}" : '';

        return match ($this->formSubmissionFollowUp($submission)) {
            FormSubmissionReport::FOLLOW_UP_RESOLVED => 'Resolved by ' . $this->formSubmissionClosedBy($submission) . $note,
            FormSubmissionReport::FOLLOW_UP_TEST => 'Marked as a test' . $note,
            FormSubmissionReport::FOLLOW_UP_SPAM => 'Marked as spam' . $note,
            FormSubmissionReport::FOLLOW_UP_SUBMITTED_LATER => 'Submitted '
                . $later->created_at->diffForHumans($submission->created_at, CarbonInterface::DIFF_ABSOLUTE)
                . ' later' . ($detailed ? " as {$later->reference}" : ''),
            FormSubmissionReport::FOLLOW_UP_UNRESOLVED => 'No record',
            default => '',
        };
    }

    /**
     * The admin who closed the submission, by name or else email address.
     */
    protected function formSubmissionClosedBy(FormSubmission $submission): string
    {
        $admin = $submission->resolvedBy;

        return $admin?->name ?: $admin?->email ?: 'an admin';
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
