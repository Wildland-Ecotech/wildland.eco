<?php

namespace App\Http\Controllers\Admin\Traits;

use Illuminate\Http\Request;

/**
 * The Period filter of admin lists: the last 30, 90, or 365 days, or all
 * time, as the `period` query value.
 */
trait FiltersByPeriod
{
    /**
     * The periods a list can show, keyed by the `period` query value: a
     * number of days back, or null for all time.
     */
    public const PERIODS = [
        '30' => 30,
        '90' => 90,
        '365' => 365,
        'all' => null,
    ];

    public const PERIOD_LABELS = [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 365 days',
        'all' => 'All time',
    ];

    public const DEFAULT_PERIOD = '30';

    /**
     * The `period` query value, or the default for anything else, such as a
     * hand-edited URL, since the form itself can only send valid values.
     */
    protected function periodFilter(Request $request): string
    {
        $period = $request->query('period');

        return is_string($period) && array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * What a list shows for the period, such as "12 donations received in
     * the last 30 days." or "No applications yet." $name is what is listed,
     * singular or plural to match $count.
     */
    protected function periodSummary(string $period, int $count, string $name): string
    {
        $days = self::PERIODS[$period];

        if ($count === 0) {
            return $days === null ? "No {$name} yet." : "No {$name} in the last {$days} days.";
        }

        return $days === null ? "{$count} {$name} to date." : "{$count} {$name} in the last {$days} days.";
    }
}
