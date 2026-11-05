<?php

namespace App\Reports;

use App\Models\Checkout;
use App\Models\FormSubmission;
use Illuminate\Support\Collection;

/**
 * Submissions of the donate and leaf application forms that need a
 * follow-up, for submissions:list, the admin Error Logs page, and the
 * dashboard's count of unresolved errors: rejected, expired, rate-limited,
 * and failed submissions, submissions that stopped without an outcome, and
 * donors who went to pay but left the payment page without finishing.
 *
 * Each one comes with the visitor's later submission of the same form that
 * went through, if any ($submission->laterSubmission): one with the same
 * email address, or for leaf applications the same phone number, at any
 * time after. A submission with one is resolved; without, unresolved.
 * Submissions an admin marked as spam are left out unless asked for.
 */
class FormSubmissionReport
{
    /**
     * A submission still marked received this long after it arrived stopped
     * without an outcome; newer ones may still be in progress.
     */
    public const IN_PROGRESS_MINUTES = 5;

    /**
     * Submissions from the last $days days, or ever with null, oldest first,
     * with their checkouts and later submissions. Only those that need a
     * follow-up are listed, unless $all includes the ones that succeeded.
     * $forms limits the list to some of FormSubmission::FORMS; with none,
     * both forms are listed. Without $resolved, only unresolved submissions
     * are listed; $spam includes those marked as spam.
     *
     * @param  array<int, string>  $forms
     * @return Collection<int, FormSubmission>
     */
    public function list(?int $days, array $forms = [], bool $all = false, bool $resolved = true, bool $spam = false): Collection
    {
        $submissions = FormSubmission::query()
            ->with(['checkout', 'markedSpamBy'])
            ->when($days !== null, fn ($query) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when($forms, fn ($query) => $query->whereIn('form', $forms))
            ->when(! $spam, fn ($query) => $query->whereNull('marked_spam_at'))
            ->orderBy('created_at')
            ->get();

        $this->findLaterSubmissions($submissions->filter($this->needsAttention(...)));

        return $submissions
            ->filter(fn (FormSubmission $submission) => $all || $this->needsAttention($submission))
            ->filter(fn (FormSubmission $submission) => $resolved || $submission->laterSubmission === null)
            ->values();
    }

    /**
     * One submission by its reference, such as a visitor quotes, whatever
     * its outcome, with its checkout and later submission, or null. Case
     * doesn't matter, since references are capital letters and digits.
     */
    public function find(string $reference): ?FormSubmission
    {
        $submission = FormSubmission::query()
            ->with(['checkout', 'markedSpamBy'])
            ->where('reference', strtoupper($reference))
            ->first();

        if ($submission !== null && $this->needsAttention($submission)) {
            $this->findLaterSubmissions(collect([$submission]));
        }

        return $submission;
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

    /**
     * Set each submission's laterSubmission: the first later submission of
     * the same form by the same visitor that went through, with what it made
     * (the leaf application, or the donation) loaded for linking to it.
     *
     * @param  Collection<int, FormSubmission>  $submissions
     */
    private function findLaterSubmissions(Collection $submissions): void
    {
        if ($submissions->isEmpty()) {
            return;
        }

        $emails = $submissions->pluck('email')->filter()->unique()->values()->all();
        $matchPhones = $submissions->contains(fn (FormSubmission $submission) => $this->matchesByPhone($submission));

        $candidates = FormSubmission::query()
            ->with(['checkout', 'leafApplication', 'donation'])
            ->whereIn('form', $submissions->pluck('form')->unique()->values()->all())
            ->where('created_at', '>', $submissions->min('created_at'))
            ->where(fn ($query) => $query
                ->where('outcome', FormSubmission::OUTCOME_SAVED)
                ->orWhere(fn ($query) => $query
                    ->where('outcome', FormSubmission::OUTCOME_SENT_TO_PAYMENT)
                    ->whereRelation('checkout', 'status', Checkout::STATUS_COMPLETED)))
            // Phone numbers are compared once their spelling is normalized,
            // so every successful leaf application is a candidate for those
            ->where(fn ($query) => $query
                ->whereIn('email', $emails)
                ->when($matchPhones, fn ($query) => $query->orWhere('form', FormSubmission::FORM_LEAF_APPLICATION)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($submissions as $submission) {
            $submission->laterSubmission = $candidates->first(
                fn (FormSubmission $candidate) => $candidate->form === $submission->form
                    && $candidate->created_at->gt($submission->created_at)
                    && $this->sameVisitor($submission, $candidate),
            );
        }
    }

    /**
     * Whether two submissions came from the same visitor: the same email
     * address, or for leaf applications the same phone number.
     */
    private function sameVisitor(FormSubmission $submission, FormSubmission $candidate): bool
    {
        if ($submission->email !== null && $submission->email === $candidate->email) {
            return true;
        }

        return $this->matchesByPhone($submission) && $submission->phoneDigits() === $candidate->phoneDigits();
    }

    /**
     * Whether a submission can be matched by its phone number: a leaf
     * application with one.
     */
    private function matchesByPhone(FormSubmission $submission): bool
    {
        return $submission->form === FormSubmission::FORM_LEAF_APPLICATION && $submission->phoneDigits() !== null;
    }
}
