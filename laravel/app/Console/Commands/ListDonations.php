<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\Donation;
use App\Reports\DonationReport;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Console\Command;
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

    /**
     * The table's columns: the key details of each payment.
     */
    private const TABLE_HEADERS = ['ID', 'Date', 'Donor', 'Type', 'Amount', 'Status'];

    public function handle(DonationReport $report): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        // Each type has an option of the same name
        $types = array_values(array_filter(DonationReport::TYPES, fn (string $type) => $this->option($type)));

        $donations = $report->list($days, $types, (bool) $this->option('all'));

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
            $this->tableTime($this->donationMadeAt($donation)),
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
     * What is being listed, such as "monthly and annual donations received",
     * or "payments" with --all, which includes payments never received.
     *
     * @param  array<int, string>  $types
     */
    private function describe(array $types, int $count): string
    {
        $which = count($types) === count(DonationReport::TYPES) ? '' : Arr::join($types, ', ', ' and ');

        return $this->option('all')
            ? trim("{$which} " . Str::plural('payment', $count))
            : trim("{$which} " . Str::plural('donation', $count)) . ' received';
    }
}
