<?php

namespace App\Reports;

use App\Models\Checkout;
use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Submissions of the donate and leaf application forms that need a
 * follow-up, for submissions:list, the admin Error Logs page, a contact's
 * Form Errors, and the dashboard's count of unresolved errors: rejected, expired, rate-limited,
 * and failed submissions, submissions that stopped without an outcome, and
 * donors who went to pay but left the payment page without finishing.
 *
 * Each one comes with the visitor's later submission of the same form that
 * went through, if any ($submission->laterSubmission): one with the same
 * email address, or for leaf applications the same phone number, at any
 * time after. followUp() says where each stands: submitted later, closed by
 * an admin as resolved, a test, or spam, or else unresolved. Spam and tests
 * are left out unless asked for.
 */
class FormSubmissionReport
{
    /**
     * A submission still marked received this long after it arrived stopped
     * without an outcome; newer ones may still be in progress.
     */
    public const IN_PROGRESS_MINUTES = 5;

    /**
     * Where a submission stands, from followUp(). An admin's closing comes
     * first, so its values match FormSubmission::RESOLUTIONS.
     */
    public const FOLLOW_UP_RESOLVED = FormSubmission::RESOLUTION_RESOLVED;

    public const FOLLOW_UP_TEST = FormSubmission::RESOLUTION_TEST;

    public const FOLLOW_UP_SPAM = FormSubmission::RESOLUTION_SPAM;

    public const FOLLOW_UP_SUBMITTED_LATER = 'submitted_later';

    public const FOLLOW_UP_UNRESOLVED = 'unresolved';

    /**
     * Submissions from the last $days days, or ever with null, oldest first,
     * with their checkouts and later submissions. Only those that need a
     * follow-up are listed, unless $all includes the ones that succeeded.
     * $forms limits the list to some of FormSubmission::FORMS; with none,
     * both forms are listed. Unresolved submissions are always listed;
     * $submittedLater and $resolvedByAdmin include those the visitor got
     * through with later and those an admin closed as resolved, and
     * $spamAndTests those closed as spam or a test. Without the first two,
     * the list holds only unresolved submissions, as the dashboard counts.
     *
     * @param  array<int, string>  $forms
     * @return Collection<int, FormSubmission>
     */
    public function list(
        ?int $days,
        array $forms = [],
        bool $all = false,
        bool $submittedLater = true,
        bool $resolvedByAdmin = true,
        bool $spamAndTests = false,
    ): Collection {
        $submissions = FormSubmission::query()
            ->with(['checkout', 'resolvedBy'])
            ->when($days !== null, fn ($query) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when($forms, fn ($query) => $query->whereIn('form', $forms))
            ->orderBy('created_at')
            ->get();

        $this->findLaterSubmissions($submissions->filter($this->needsAttention(...)));

        return $submissions
            ->filter(fn (FormSubmission $submission) => $all || $this->needsAttention($submission))
            ->filter(fn (FormSubmission $submission) => match ($this->followUp($submission)) {
                self::FOLLOW_UP_SUBMITTED_LATER => $submittedLater,
                self::FOLLOW_UP_RESOLVED => $resolvedByAdmin,
                self::FOLLOW_UP_TEST, self::FOLLOW_UP_SPAM => $spamAndTests,
                // Unresolved, or with $all one that succeeded
                default => true,
            })
            ->values();
    }

    /**
     * The submissions needing a follow-up that came from a contact, newest
     * first, closed ones too, with their checkouts and later submissions:
     * those that made one of their leaf applications, or with one of their
     * emails, or one of their phone numbers once its spelling is normalized,
     * current or previous.
     *
     * @return Collection<int, FormSubmission>
     */
    public function forContact(Contact $contact): Collection
    {
        $details = $contact->details()->get();
        $emails = $details->where('type', ContactDetail::TYPE_EMAIL)->pluck('normalized')->filter()->unique()->values()->all();
        $phones = $details->where('type', ContactDetail::TYPE_PHONE)->pluck('normalized')->filter()->unique()->values()->all();
        $applications = $contact->applications()->withTrashed()->pluck('id')->all();

        if ($emails === [] && $phones === [] && $applications === []) {
            return collect();
        }

        // A phone number's digits, without a leading US country code 1
        $digits = "regexp_replace(regexp_replace(data->>'phone', '[^0-9]', '', 'g'), '^1([0-9]{10})$', '\\1')";

        $submissions = FormSubmission::query()
            ->with(['checkout', 'resolvedBy'])
            ->where(fn ($query) => $query
                ->whereIn('email', $emails)
                ->orWhereIn('leaf_application_id', $applications)
                ->when($phones !== [], fn ($query) => $query->orWhereIn(DB::raw($digits), $phones)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter($this->needsAttention(...))
            ->values();

        $this->findLaterSubmissions($submissions);

        return $submissions;
    }

    /**
     * One submission by its reference, such as a visitor quotes, whatever
     * its outcome, with its checkout and later submission, or null. Case
     * doesn't matter, since references are capital letters and digits.
     */
    public function find(string $reference): ?FormSubmission
    {
        $submission = FormSubmission::query()
            ->with(['checkout', 'resolvedBy'])
            ->where('reference', strtoupper($reference))
            ->first();

        if ($submission !== null && $this->needsAttention($submission)) {
            $this->findLaterSubmissions(collect([$submission]));
        }

        return $submission;
    }

    /**
     * Where a submission stands: one of the FOLLOW_UP_ values, or null for
     * one that went through or is still in progress. An admin's closing
     * comes first, then whether the visitor submitted the form later. Get
     * the submission from list() or find(), which look up later submissions.
     */
    public function followUp(FormSubmission $submission): ?string
    {
        return match (true) {
            $submission->isClosed() => $submission->resolution,
            $submission->laterSubmission !== null => self::FOLLOW_UP_SUBMITTED_LATER,
            $this->needsAttention($submission) => self::FOLLOW_UP_UNRESOLVED,
            default => null,
        };
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
