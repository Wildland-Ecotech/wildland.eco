<?php

namespace App\Reports;

use App\Models\Donation;
use App\Models\Subscription;
use App\Payments\Stripe\StripeGateway;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Donations, one payment at a time, for donations:list, donations:view, and
 * the admin pages. Recurring donations themselves, with their status, are
 * left to a future subscriptions report.
 */
class DonationReport
{
    use DescribesDonations;

    public const TYPE_ONE_TIME = 'one-time';

    public const TYPE_MONTHLY = 'monthly';

    public const TYPE_ANNUAL = 'annual';

    /**
     * The donation types a list can be limited to. donations:list has an
     * option of the same name for each.
     */
    public const TYPES = [self::TYPE_ONE_TIME, self::TYPE_MONTHLY, self::TYPE_ANNUAL];

    /**
     * The subscription interval of each recurring type. One-time donations
     * have no subscription.
     */
    private const INTERVALS = [
        self::TYPE_MONTHLY => Subscription::INTERVAL_MONTH,
        self::TYPE_ANNUAL => Subscription::INTERVAL_YEAR,
    ];

    /**
     * When a payment was made, in SQL, matching donationMadeAt(). Times are
     * stored in UTC, without a time zone.
     */
    private const MADE_AT_SQL = "COALESCE(paid_at, to_timestamp(CAST(gateway_data->>'created' AS bigint)) AT TIME ZONE 'UTC', created_at)";

    /**
     * Payments made in the last $days days, or ever with null, oldest first,
     * loaded with donationRelations(). Only payments we received are listed,
     * including ones refunded later, unless $all includes pending and failed
     * payments too. $types limits the list to some of TYPES; with none, every
     * type is listed. Soft-deleted donations are left out.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, Donation>
     */
    public function list(?int $days, array $types = [], bool $all = false): Collection
    {
        return Donation::query()
            ->with($this->donationRelations())
            ->when(! $all, fn (Builder $query) => $query->received())
            ->when($types, fn (Builder $query) => $this->whereType($query, $types))
            ->when($days !== null, fn (Builder $query) => $query->whereRaw(self::MADE_AT_SQL . ' >= ?', [now()->subDays($days)]))
            ->orderByRaw(self::MADE_AT_SQL)
            ->orderBy('id')
            ->get();
    }

    /**
     * One donation by its ID or its Stripe payment ID (pi_...), loaded with
     * donationRelations(), or null. A soft-deleted donation is found too,
     * since it was asked for by ID.
     */
    public function find(string $id): ?Donation
    {
        return ctype_digit($id)
            ? Donation::withTrashed()->with($this->donationRelations())->find((int) $id)
            : Donation::findByGatewayId(StripeGateway::NAME, $id)?->load($this->donationRelations());
    }

    /**
     * Limit the query to payments of the given types.
     *
     * @param  Builder<Donation>  $query
     * @param  array<int, string>  $types
     */
    private function whereType(Builder $query, array $types): void
    {
        $intervals = array_values(array_intersect_key(self::INTERVALS, array_flip($types)));

        $query->where(function (Builder $query) use ($types, $intervals) {
            if (in_array(self::TYPE_ONE_TIME, $types, true)) {
                $query->orWhereNull('subscription_id');
            }

            if ($intervals !== []) {
                $query->orWhereHas('subscription', fn (Builder $subscription) => $subscription->withTrashed()->whereIn('interval', $intervals));
            }
        });
    }
}
