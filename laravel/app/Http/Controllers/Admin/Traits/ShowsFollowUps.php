<?php

namespace App\Http\Controllers\Admin\Traits;

use App\Models\FormSubmission;
use App\Reports\FormSubmissionReport;

/**
 * How admin pages show where a form submission that didn't go through
 * stands: an icon beside the follow-up's words, linking to what the
 * visitor's later submission made. Shared by Error Logs and a contact's
 * Form Errors. For classes using DescribesFormSubmissions.
 */
trait ShowsFollowUps
{
    /**
     * The status icon for each follow-up (see the admin-records component),
     * and the words read out with it on a phone's line.
     */
    protected const FOLLOW_UP_ICONS = [
        FormSubmissionReport::FOLLOW_UP_SUBMITTED_LATER => ['status' => 'success', 'label' => 'Submitted later'],
        FormSubmissionReport::FOLLOW_UP_RESOLVED => ['status' => 'success', 'label' => 'Resolved'],
        FormSubmissionReport::FOLLOW_UP_TEST => ['status' => 'muted', 'label' => 'Test'],
        FormSubmissionReport::FOLLOW_UP_SPAM => ['status' => 'muted', 'label' => 'Spam'],
        FormSubmissionReport::FOLLOW_UP_UNRESOLVED => ['status' => 'failure', 'label' => 'No record'],
    ];

    /**
     * The follow-up's icon and words, from FOLLOW_UP_ICONS, or null for a
     * submission that went through.
     *
     * @return array{status: string, label: string}|null
     */
    protected function followUpIcon(FormSubmission $submission): ?array
    {
        return self::FOLLOW_UP_ICONS[$this->formSubmissionFollowUp($submission)] ?? null;
    }

    /**
     * A status cell for the follow-up's words, linking to what the later
     * submission made, or the words alone for a submission that went
     * through.
     *
     * @return array{text: string, status: string, url: string|null}|string
     */
    protected function followUpCell(FormSubmission $submission, string $text): array|string
    {
        $icon = $this->followUpIcon($submission);

        return $icon === null ? $text : ['text' => $text, 'status' => $icon['status'], 'url' => $this->laterRecordUrl($submission)];
    }

    /**
     * The page of what the visitor's later submission made: the leaf
     * application, or the donation. Null without one, or before the
     * donation has arrived from the payment processor.
     */
    protected function laterRecordUrl(FormSubmission $submission): ?string
    {
        // An admin's closing replaces the link, as it does the words
        if ($submission->isClosed()) {
            return null;
        }

        $later = $submission->laterSubmission;

        return match (true) {
            $later?->leafApplication !== null => route('admin.leaves.show', $later->leafApplication->id),
            $later?->donation !== null => route('admin.donations.show', $later->donation->id),
            default => null,
        };
    }
}
