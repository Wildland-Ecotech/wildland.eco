<?php

namespace App\Reports;

use App\Reports\Traits\DescribesFollowUps;

/**
 * The dashboard's warning while contacts are waiting to hear from us or have
 * bags ready and haven't heard from us yet, counted as Contacts' Needs
 * follow-up lists them. Its Follow Up button opens that list, so the other
 * pages don't repeat it.
 */
class FollowUpNotice
{
    use DescribesFollowUps;

    public function __construct(private FollowUpReport $report) {}

    /**
     * Such as "3 people are waiting to hear from us, and 2 more have bags
     * ready.", or null when nobody is waiting or ready.
     */
    public function text(): ?string
    {
        $followUps = $this->followUpsByContact($this->report);
        $waiting = $followUps->where('tier', FollowUpReport::WAITING)->count();
        $ready = $followUps->where('tier', FollowUpReport::READY)->count();
        $people = fn (int $count) => $count === 1 ? '1 person' : "{$count} people";

        return match (true) {
            $waiting > 0 && $ready > 0 => $people($waiting) . ($waiting === 1 ? ' is' : ' are') . " waiting to hear from us, and {$ready} more " . ($ready === 1 ? 'has' : 'have') . ' bags ready.',
            $waiting > 0 => $people($waiting) . ($waiting === 1 ? ' is' : ' are') . ' waiting to hear from us.',
            $ready > 0 => $people($ready) . ($ready === 1 ? ' has' : ' have') . ' bags ready and ' . ($ready === 1 ? 'hasn’t' : 'haven’t') . ' heard from us yet.',
            default => null,
        };
    }
}
