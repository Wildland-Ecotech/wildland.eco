<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Reports\LeafApplicationReport;
use App\Reports\Traits\DescribesLeafApplications;
use Illuminate\Console\Command;

/**
 * Lists recent leaf program applications as a table, for an overview, like
 * the admin leaf applications page (/admin/leaves/applications).
 * leaves:view shows one in full. The output contains donors' personal contact details: never paste it
 * into issues, chat, or the repository.
 */
class ListLeafApplications extends Command
{
    use DescribesLeafApplications, ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'leaves:applications
        {--days=30 : Show applications from this many days back}
        {--csv : Output CSV with every detail, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List recent leaf program applications';

    /**
     * The table's columns: the key details of each application. The bag
     * count lines up on the right.
     */
    public const HEADERS = self::LEAF_APPLICATION_TABLE_HEADERS;

    public const NUMBER_COLUMNS = [4];

    public function handle(LeafApplicationReport $report): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $applications = $report->list($days);

        if ($this->option('csv')) {
            $this->writeCsv($this->leafApplicationCsvHeader(), $applications->map($this->leafApplicationCsvRow(...)));

            return self::SUCCESS;
        }

        if ($applications->isEmpty()) {
            $this->components->info("No applications in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->writeTable(self::HEADERS, $applications->map($this->leafApplicationTableRow(...)), self::NUMBER_COLUMNS);

        $count = $applications->count();
        $this->components->info("{$count} {$this->leafApplicationListName([], $count)} in the last {$days} days. Show one in full with leaves:view and its ID.");

        return self::SUCCESS;
    }
}
