<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Reports\DonationReport;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Console\Command;

/**
 * Lists donations one payment at a time, one-time or from a monthly or annual
 * donation, as a table for an overview of recent giving, like the admin
 * donations page (/admin/donations). donations:view shows one in full.
 * Recurring donations themselves, with their status, are left to a future
 * subscriptions:list. The output contains donors' names and email addresses:
 * never paste it into issues, chat, or the repository.
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

    public function handle(DonationReport $report): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        // Each type has an option of the same name
        $types = array_values(array_filter(DonationReport::TYPES, fn (string $type) => $this->option($type)));
        $all = (bool) $this->option('all');

        $donations = $report->list($days, $types, $all);

        if ($this->option('csv')) {
            $this->writeCsv($this->donationCsvHeader(), $donations->map($this->donationCsvRow(...)));

            return self::SUCCESS;
        }

        if ($donations->isEmpty()) {
            $this->components->info("No {$this->donationListName($types, $all, 2)} in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->writeTable(self::DONATION_TABLE_HEADERS, $donations->map($this->donationTableRow(...)));

        $count = $donations->count();
        $this->components->info("{$count} {$this->donationListName($types, $all, $count)} in the last {$days} days. Show one in full with donations:view and its ID.");

        return self::SUCCESS;
    }
}
