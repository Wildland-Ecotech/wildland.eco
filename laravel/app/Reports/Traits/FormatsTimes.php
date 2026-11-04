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
        $local = $time->copy()->setTimezone(config('organization.timezone'));

        return $local->format($local->year === now(config('organization.timezone'))->year ? 'M j' : 'M j, Y');
    }
}
