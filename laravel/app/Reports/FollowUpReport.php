<?php

namespace App\Reports;

use App\Models\ContactActivity;
use App\Models\LeafApplication;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who needs to hear from us next: pending leaf applications in the order to
 * follow up with them, so nobody waits long without hearing from us.
 *
 * Reaching someone means a text or call from us, or a visit, logged on
 * their timeline since they applied; notes don't count. Each application
 * gets the first of these reasons that fits, checked in this order:
 *
 * - UNANSWERED (waiting on us): their last text or call came from them.
 * - FOLLOW_UP_DUE (waiting on us): the follow-up date an admin set has
 *   come, and nobody has reached them since it.
 * - FOLLOW_UP_LATER (later): that date is still to come.
 * - NOT_REACHED (waiting on us): nobody has reached them a week
 *   (REACH_WITHIN_DAYS) after they applied.
 * - READY_LATER (later): their bags won't be ready for more than a week
 *   (READY_SOON_DAYS), so they move up a week before their ready date.
 * - READY (ready): nobody has reached them yet, and their bags are ready
 *   now or within a week.
 * - CONTACTED (contacted): we've reached them, and the pickup or drop-off
 *   is still to come.
 *
 * The groups are listed in TIERS order, each oldest application first.
 */
class FollowUpReport
{
    /**
     * Dates are Fort Collins dates, such as for "ready within a week".
     */
    public const TIMEZONE = 'America/Denver';

    public const REACH_WITHIN_DAYS = 7;

    public const READY_SOON_DAYS = 7;

    /**
     * The groups, in the order they're listed.
     */
    public const WAITING = 'waiting';

    public const READY = 'ready';

    public const CONTACTED = 'contacted';

    public const LATER = 'later';

    public const TIERS = [self::WAITING, self::READY, self::CONTACTED, self::LATER];

    /**
     * Why an application is in its group. READY and CONTACTED are reasons
     * as well as groups.
     */
    public const UNANSWERED = 'unanswered';

    public const FOLLOW_UP_DUE = 'follow_up_due';

    public const FOLLOW_UP_LATER = 'follow_up_later';

    public const NOT_REACHED = 'not_reached';

    public const READY_LATER = 'ready_later';

    public const TIER_OF = [
        self::UNANSWERED => self::WAITING,
        self::FOLLOW_UP_DUE => self::WAITING,
        self::FOLLOW_UP_LATER => self::LATER,
        self::NOT_REACHED => self::WAITING,
        self::READY_LATER => self::LATER,
        self::READY => self::READY,
        self::CONTACTED => self::CONTACTED,
    ];

    /**
     * Pending applications, not deleted, in the order to follow up. Each
     * comes with its group, its reason, and when we last reached them since
     * they applied, or null. The application's contact is loaded, with its
     * conversations (texts, calls, and visits), oldest first.
     *
     * @return Collection<int, array{application: LeafApplication, tier: string, reason: string, last_reached_at: ?CarbonInterface}>
     */
    public function pending(): Collection
    {
        $today = Carbon::today(self::TIMEZONE)->toDateString();
        $readySoon = Carbon::today(self::TIMEZONE)->addDays(self::READY_SOON_DAYS)->toDateString();
        $reachBy = now()->subDays(self::REACH_WITHIN_DAYS);

        return LeafApplication::pending()
            ->with(['contact.activities' => fn ($query) => $query
                ->whereIn('type', ContactActivity::CONVERSATION_TYPES)
                ->orderBy('happened_at')
                ->orderBy('id')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (LeafApplication $application) use ($today, $readySoon, $reachBy) {
                $since = ($application->contact?->activities ?? collect())
                    ->filter(fn (ContactActivity $activity) => $activity->happened_at->gte($application->created_at))
                    ->values();
                $reached = $since->filter(fn (ContactActivity $activity) => $activity->reachedThem())->last();
                $reason = $this->reason($application, $since->last(), $reached, $today, $readySoon, $reachBy);

                return [
                    'application' => $application,
                    'tier' => self::TIER_OF[$reason],
                    'reason' => $reason,
                    'last_reached_at' => $reached?->happened_at,
                ];
            })
            // A stable sort, so each group stays oldest first
            ->sortBy(fn (array $item) => array_search($item['tier'], self::TIERS, true))
            ->values();
    }

    /**
     * The first reason that fits (see the class description).
     */
    private function reason(
        LeafApplication $application,
        ?ContactActivity $last,
        ?ContactActivity $reached,
        string $today,
        string $readySoon,
        CarbonInterface $reachBy,
    ): string {
        $followUp = $application->follow_up_on?->toDateString();
        $followUpOpen = $followUp !== null
            && ($reached === null || $reached->happened_at->copy()->timezone(self::TIMEZONE)->toDateString() < $followUp);
        $readyDate = $application->ready_now ? null : $application->ready_date?->toDateString();

        return match (true) {
            $last?->direction === ContactActivity::DIRECTION_INCOMING => self::UNANSWERED,
            $followUpOpen && $followUp <= $today => self::FOLLOW_UP_DUE,
            $followUpOpen => self::FOLLOW_UP_LATER,
            $reached === null && $application->created_at->lte($reachBy) => self::NOT_REACHED,
            $readyDate !== null && $readyDate > $readySoon => self::READY_LATER,
            $reached === null => self::READY,
            default => self::CONTACTED,
        };
    }
}
