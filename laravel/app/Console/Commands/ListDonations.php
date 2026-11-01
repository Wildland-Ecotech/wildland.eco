<?php

namespace App\Console\Commands;

use App\Console\Traits\DescribesDonations;
use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\Donation;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Lists donations one payment at a time, one-time or from a monthly or annual
 * donation, as a table for an overview of recent giving until the
 * administrator pages are built (milestone 10). donations:view shows one in
 * full. Recurring donations themselves, with their status, are left to a
 * future subscriptions:list. The output contains donors' names and email
 * addresses: never paste it into issues, chat, or the repository.
 */
class ListDonations extends Command
{
    use DescribesDonations, ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'donations:list
        {--days=30 : Show donations from this many days back}
        {--one-time : Only one-time donations}
        {--monthly : Only payments of monthly donations}
        {--annual : Only payments of annual donations}
        {--all : Include pending and failed payments}
        {--csv : Output CSV with every detail, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List donations, one payment at a time';

    public const TYPE_ONE_TIME = 'one-time';

    public const TYPE_MONTHLY = 'monthly';

    public const TYPE_ANNUAL = 'annual';

    /**
     * The donation types, each also the name of the option that selects it.
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
     * The table's columns: the key details of each payment.
     */
    private const TABLE_HEADERS = ['ID', 'Date', 'Donor', 'Type', 'Amount', 'Status'];

    /**
     * Dates in the table, such as "Oct 21, 2026 7:30 PM MDT".
     */
    private const TABLE_DATE_FORMAT = 'M j, Y g:i A T';

    /**
     * When a payment was made, in SQL, matching donationMadeAt(). Times are
     * stored in UTC, without a time zone.
     */
    private const MADE_AT_SQL = "COALESCE(paid_at, to_timestamp(CAST(gateway_data->>'created' AS bigint)) AT TIME ZONE 'UTC', created_at)";

    public function handle(): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $types = array_values(array_filter(self::TYPES, fn (string $type) => $this->option($type)));

        $donations = Donation::query()
            ->with($this->donationRelations())
            ->when(! $this->option('all'), fn (Builder $query) => $query->received())
            ->when($types, fn (Builder $query) => $this->whereType($query, $types))
            ->whereRaw(self::MADE_AT_SQL . ' >= ?', [now()->subDays($days)])
            ->orderByRaw(self::MADE_AT_SQL)
            ->orderBy('id')
            ->get();

        if ($this->option('csv')) {
            $this->writeCsv(
                ['ID', 'Payment ID', 'Date', ...self::DONATION_LABELS, 'Currency'],
                $donations->map(fn (Donation $donation) => [
                    $donation->id,
                    $donation->gateway_payment_id,
                    $this->localTime($this->donationMadeAt($donation)),
                    ...array_values($this->donationFields($donation, csv: true)),
                    strtoupper($donation->currency),
                ]),
            );

            return self::SUCCESS;
        }

        if ($donations->isEmpty()) {
            $this->components->info("No {$this->describe($types, 2)} in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->writeTable(self::TABLE_HEADERS, $donations->map(fn (Donation $donation) => [
            $donation->id,
            $this->localTime($this->donationMadeAt($donation), self::TABLE_DATE_FORMAT),
            $this->donor($donation),
            $this->donationType($donation),
            $this->money($donation->amount_cents, $donation->currency),
            $this->donationStatus($donation),
        ]));

        $count = $donations->count();
        $this->components->info("{$count} {$this->describe($types, $count)} in the last {$days} days. Show one in full with donations:view and its ID.");

        return self::SUCCESS;
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

    /**
     * What is being listed, such as "monthly and annual donations received",
     * or "payments" with --all, which includes payments never received.
     *
     * @param  array<int, string>  $types
     */
    private function describe(array $types, int $count): string
    {
        $which = count($types) === count(self::TYPES) ? '' : Arr::join($types, ', ', ' and ');

        return $this->option('all')
            ? trim("{$which} " . Str::plural('payment', $count))
            : trim("{$which} " . Str::plural('donation', $count)) . ' received';
    }
}
