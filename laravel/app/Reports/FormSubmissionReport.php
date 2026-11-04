<?php

namespace App\Reports;

use App\Models\Checkout;
use App\Models\FormSubmission;
use Illuminate\Support\Collection;

/**
 * Submissions of the donate and leaf application forms that need a
 * follow-up, for submissions:list and the admin pages: rejected, expired,
 * rate-limited, and failed submissions, submissions that stopped without an
 * outcome, and donors who went to pay but left the payment page without
 * finishing.
 */
class FormSubmissionReport
{
    /**
     * A submission still marked received this long after it arrived stopped
     * without an outcome; newer ones may still be in progress.
     */
    public const IN_PROGRESS_MINUTES = 5;

    /**
     * Submissions from the last $days days, oldest first, with their
     * checkouts. Only those that need a follow-up are listed, unless $all
     * includes the ones that succeeded. $form limits the list to one of
     * FormSubmission::FORMS.
     *
     * @return Collection<int, FormSubmission>
     */
    public function list(int $days, ?string $form = null, bool $all = false): Collection
    {
        return FormSubmission::query()
            ->with('checkout')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($form, fn ($query) => $query->where('form', $form))
            ->orderBy('created_at')
            ->get()
            ->when(! $all, fn (Collection $submissions) => $submissions->filter($this->needsAttention(...)))
            ->values();
    }

    /**
     * Whether a submission didn't get the visitor what they came for.
     */
    public function needsAttention(FormSubmission $submission): bool
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
}
