<?php

namespace App\Reports;

use App\Models\ContactActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The leaf ledger, for the admin Leaf Ledger page: logged pickups and
 * drop-offs, with bags, by season. A season is named for its autumn and
 * runs from July 1 to June 30 in Fort Collins time, so Autumn 2026 holds
 * leaves collected from July 2026 through June 2027, including late
 * deliveries in the winter and spring.
 */
class LeafLedgerReport
{
    /**
     * The month each season starts, in Fort Collins time.
     */
    public const SEASON_START_MONTH = 7;

    /**
     * Pickups and drop-offs logged for the season, oldest first, with their
     * contact and the admin who logged them. Deleted entries, and entries
     * of deleted contacts, are left out.
     *
     * @return Collection<int, ContactActivity>
     */
    public function list(int $season): Collection
    {
        [$start, $end] = $this->range($season);

        return ContactActivity::query()
            ->with(['contact', 'loggedBy' => fn ($query) => $query->withTrashed()])
            ->whereIn('type', ContactActivity::COMPLETING_TYPES)
            ->where('happened_at', '>=', $start)
            ->where('happened_at', '<', $end)
            ->whereHas('contact', fn ($query) => $query->withoutTrashed())
            ->orderBy('happened_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The season a time falls in, by the year its autumn is in.
     */
    public function season(CarbonInterface $time): int
    {
        $local = $time->copy()->setTimezone(config('organization.timezone'));

        return $local->month >= self::SEASON_START_MONTH ? $local->year : $local->year - 1;
    }

    /**
     * The season now.
     */
    public function current(): int
    {
        return $this->season(now());
    }

    /**
     * Every season from the first logged pickup or drop-off through the
     * current one, newest first, for the season menu.
     *
     * @return list<int>
     */
    public function seasons(): array
    {
        $first = ContactActivity::query()->whereIn('type', ContactActivity::COMPLETING_TYPES)->min('happened_at');
        $current = $this->current();
        $earliest = $first === null ? $current : min($current, $this->season(CarbonImmutable::parse($first, 'UTC')));

        return range($current, $earliest);
    }

    /**
     * A season's name, such as "Autumn 2026".
     */
    public function name(int $season): string
    {
        return "Autumn {$season}";
    }

    /**
     * When a season starts and the next one starts, in UTC for querying.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(int $season): array
    {
        $start = CarbonImmutable::create($season, self::SEASON_START_MONTH, 1, 0, 0, 0, config('organization.timezone'));

        return [$start->utc(), $start->addYear()->utc()];
    }
}
