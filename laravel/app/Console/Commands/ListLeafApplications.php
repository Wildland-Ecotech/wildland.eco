<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\LeafApplication;
use App\Reports\Traits\DescribesLeafApplications;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lists recent leaf program applications as a table, for an overview until
 * the administrator pages are built (milestone 10). leaves:view shows one in
 * full. The output contains donors' personal contact details: never paste it
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
    public const HEADERS = ['ID', 'Applied', 'Name', 'Type', 'Bags', 'Ready'];

    public const NUMBER_COLUMNS = [4];

    public function handle(): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $applications = LeafApplication::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($this->option('csv')) {
            $this->writeCsv(
                ['ID', 'Applied', ...self::LEAF_APPLICATION_LABELS],
                $applications->map(fn (LeafApplication $application) => [
                    $application->id,
                    $this->localTime($application->created_at),
                    ...array_values($this->leafApplicationFields($application)),
                ]),
            );

            return self::SUCCESS;
        }

        if ($applications->isEmpty()) {
            $this->components->info("No applications in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->writeTable(self::HEADERS, $applications->map(fn (LeafApplication $application) => [
            $application->id,
            $this->tableTime($application->created_at),
            $application->name,
            $this->leafApplicationType($application),
            $application->estimated_bag_count,
            $this->bagsReady($application),
        ]), self::NUMBER_COLUMNS);

        $count = $applications->count();
        $this->components->info("{$count} " . Str::plural('application', $count) . " in the last {$days} days. Show one in full with leaves:view and its ID.");

        return self::SUCCESS;
    }
}
