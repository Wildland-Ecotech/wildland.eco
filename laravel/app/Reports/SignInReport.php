<?php

namespace App\Reports;

use App\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The attempts to sign in that RecordLoginAttempt records, for the admin
 * Sign-Ins page (/admin/sign-ins), and the warning on the dashboard and
 * Error Logs while any failed in the last day, which links there.
 */
class SignInReport
{
    /**
     * The most attempts the page lists, newest first. They're kept
     * indefinitely, so the page says when there are more.
     */
    public const LIST_LIMIT = 500;

    /**
     * How far back the warning looks for failed attempts.
     */
    public const RECENT_HOURS = 24;

    /**
     * The newest attempts, up to LIST_LIMIT: only those that failed with
     * $failedOnly, and only those whose email or IP address contains
     * $search when it isn't empty.
     *
     * @return Collection<int, LoginAttempt>
     */
    public function list(bool $failedOnly = false, string $search = ''): Collection
    {
        return $this->query($failedOnly, $search)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * How many attempts list() would show without its limit.
     */
    public function count(bool $failedOnly = false, string $search = ''): int
    {
        return $this->query($failedOnly, $search)->count();
    }

    /**
     * The attempts that failed in the last RECENT_HOURS: how many, and the
     * IP address most of them came from, with how many. Of IP addresses
     * with as many, the one that tried last.
     *
     * @return array{count: int, ip: ?string, fromIp: int}
     */
    public function recentFailures(): array
    {
        $recent = LoginAttempt::query()
            ->where('outcome', '!=', LoginAttempt::OUTCOME_SIGNED_IN)
            ->where('created_at', '>=', now()->subHours(self::RECENT_HOURS));

        $busiest = (clone $recent)
            ->selectRaw('ip_address, count(*) as attempts, max(created_at) as latest')
            ->groupBy('ip_address')
            ->orderByDesc('attempts')
            ->orderByDesc('latest')
            ->first();

        return [
            'count' => $recent->count(),
            'ip' => $busiest?->ip_address,
            'fromIp' => (int) ($busiest?->attempts ?? 0),
        ];
    }

    /**
     * The warning, such as "5 failed sign-in attempts in the last day, 3
     * from 203.0.113.77.", or null when none failed.
     */
    public function notice(): ?string
    {
        ['count' => $count, 'ip' => $ip, 'fromIp' => $fromIp] = $this->recentFailures();

        if ($count === 0) {
            return null;
        }

        $attempts = $count === 1 ? '1 failed sign-in attempt' : number_format($count) . ' failed sign-in attempts';

        $from = match (true) {
            $count === 1 => "from {$ip}",
            $fromIp === $count => "all from {$ip}",
            $fromIp === 1 => 'each from a different IP address',
            default => number_format($fromIp) . " from {$ip}",
        };

        return "{$attempts} in the last day, {$from}.";
    }

    /**
     * @return Builder<LoginAttempt>
     */
    private function query(bool $failedOnly, string $search): Builder
    {
        $query = LoginAttempt::query();

        if ($failedOnly) {
            $query->where('outcome', '!=', LoginAttempt::OUTCOME_SIGNED_IN);
        }

        if ($search !== '') {
            // Email addresses are recorded in lowercase
            $text = '%' . addcslashes(mb_strtolower($search), '\\%_') . '%';

            $query->where(fn (Builder $query) => $query->whereLike('email', $text)->orWhereLike('ip_address', $text));
        }

        return $query;
    }
}
