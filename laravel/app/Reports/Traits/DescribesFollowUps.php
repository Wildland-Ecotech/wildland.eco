<?php

namespace App\Reports\Traits;

use App\Models\LeafApplication;
use App\Reports\FollowUpReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How the admin pages describe who needs follow-up (FollowUpReport), so
 * Contacts, a contact's page, and the dashboard say the same: each group's
 * label, and why someone is in it, such as "No word from us in 10 days".
 */
trait DescribesFollowUps
{
    use FormatsTimes;

    /**
     * The groups' labels, in FollowUpReport::TIERS order.
     */
    protected const FOLLOW_UP_GROUP_LABELS = [
        FollowUpReport::WAITING => 'Waiting on us',
        FollowUpReport::READY => 'Ready',
        FollowUpReport::CONTACTED => 'Contacted',
        FollowUpReport::LATER => 'Later',
    ];

    /**
     * Each contact's first pending application in the follow-up order, as
     * FollowUpReport::pending() gives it, keyed by contact ID, in that order.
     * Applications not linked to a contact are left out, since Needs Review
     * lists them.
     *
     * @return Collection<int, array{application: LeafApplication, tier: string, reason: string, last_reached_at: ?\Carbon\CarbonInterface}>
     */
    protected function followUpsByContact(FollowUpReport $report): Collection
    {
        return $report->pending()
            ->filter(fn (array $item) => $item['application']->contact_id !== null)
            ->unique(fn (array $item) => $item['application']->contact_id)
            ->keyBy(fn (array $item) => $item['application']->contact_id);
    }

    /**
     * Why an application is in its group, such as "Their text on Nov 5
     * needs a reply", "Follow-up due today", "No word from us in 10 days",
     * "Bags ready Dec 5", or "Reached Nov 2".
     *
     * @param  array{application: LeafApplication, tier: string, reason: string, last_reached_at: ?\Carbon\CarbonInterface}  $item
     */
    protected function followUpReason(array $item): string
    {
        $application = $item['application'];
        $today = Carbon::today(FollowUpReport::TIMEZONE)->toDateString();

        return match ($item['reason']) {
            FollowUpReport::UNANSWERED => $this->unansweredReason($application),
            FollowUpReport::FOLLOW_UP_DUE => $application->follow_up_on->toDateString() === $today
                ? 'Follow-up due today'
                : 'Follow-up was due ' . $application->follow_up_on->format('M j'),
            FollowUpReport::FOLLOW_UP_LATER => 'Follow up on ' . $application->follow_up_on->format('M j'),
            FollowUpReport::NOT_REACHED => 'No word from us in ' . (int) floor($application->created_at->diffInDays(now(), true)) . ' days',
            FollowUpReport::READY_LATER => 'Bags ready ' . $application->ready_date->format('M j'),
            FollowUpReport::READY => match (true) {
                $application->ready_now => 'Bags ready now',
                $application->ready_date->toDateString() <= $today => 'Bags ready since ' . $application->ready_date->format('M j'),
                default => 'Bags ready ' . $application->ready_date->format('M j'),
            },
            FollowUpReport::CONTACTED => 'Reached ' . $this->shortDate($item['last_reached_at']),
        };
    }

    /**
     * The text or call from them that's waiting for a reply: the last of
     * their contact's conversations, which FollowUpReport loads.
     */
    private function unansweredReason(LeafApplication $application): string
    {
        $last = $application->contact->activities->last();

        return "Their {$last->type} on {$this->shortDate($last->happened_at)} needs a reply";
    }
}
