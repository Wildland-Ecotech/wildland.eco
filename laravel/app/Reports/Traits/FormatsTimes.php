<?php

namespace App\Reports\Traits;

use Carbon\CarbonInterface;

/**
 * Times in reports, in commands and admin pages, in Fort Collins time
 * (`organization.timezone`). Times are stored in UTC.
 */
trait FormatsTimes
{
    /**
     * A time in Fort Collins time, by default such as "Wed, Oct 21, 2026
     * 7:30 PM MDT".
     */
    protected function localTime(CarbonInterface $time, string $format = 'D, M j, Y g:i A T'): string
    {
        return $time->copy()->setTimezone(config('organization.timezone'))->format($format);
    }

    /**
     * A time in Fort Collins time for a table column, without the weekday,
     * such as "Oct 21, 2026 7:30 PM MDT".
     */
    protected function tableTime(CarbonInterface $time): string
    {
        return $this->localTime($time, 'M j, Y g:i A T');
    }

    /**
     * A date in Fort Collins time for a short line, such as "Oct 21", with
     * the year only when it isn't this year, such as "Oct 21, 2025".
     */
    protected function shortDate(CarbonInterface $time): string
    {
        return $this->thisYearOrNot($time, 'M j', 'M j, Y');
    }

    /**
     * A time in Fort Collins time for an admin page's list, where every time
     * is local, so without the time zone: such as "Oct 21, 7:30 PM", with
     * the year only when it isn't this year, such as "Oct 21, 2025, 7:30 PM".
     * Commands keep tableTime(), since a terminal has room for the zone.
     */
    protected function listTime(CarbonInterface $time): string
    {
        return $this->thisYearOrNot($time, 'M j, g:i A', 'M j, Y, g:i A');
    }

    /**
     * A time in Fort Collins time in $thisYear's format if it's this year,
     * or else $otherYear's.
     */
    private function thisYearOrNot(CarbonInterface $time, string $thisYear, string $otherYear): string
    {
        $local = $time->copy()->setTimezone(config('organization.timezone'));

        return $local->format($local->year === now(config('organization.timezone'))->year ? $thisYear : $otherYear);
    }
}
