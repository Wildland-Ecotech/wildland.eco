<?php

namespace App\Reports\Traits;

use App\Models\Checkout;
use App\Models\FormSubmission;
use App\Models\LeafApplication;

/**
 * How reports describe a form submission, so submissions:list, its CSV, and
 * the admin pages show the same details. Query submissions with their
 * checkout loaded.
 */
trait DescribesFormSubmissions
{
    /**
     * Labels for a submission's fields, in order.
     */
    protected const FORM_SUBMISSION_LABELS = ['Form', 'What happened', 'Name', 'Email', 'Details', 'Errors shown', 'Browser'];

    /**
     * A submission's fields, keyed by FORM_SUBMISSION_LABELS.
     *
     * @return array<string, string>
     */
    protected function formSubmissionFields(FormSubmission $submission): array
    {
        return array_combine(self::FORM_SUBMISSION_LABELS, [
            $submission->form === FormSubmission::FORM_DONATION ? 'Donation' : 'Leaf application',
            $this->formSubmissionOutcome($submission),
            (string) $submission->name,
            (string) $submission->email,
            $this->formSubmissionDetails($submission),
            collect($submission->errors ?? [])->flatten()->unique()->implode(' '),
            (string) $submission->user_agent,
        ]);
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
