<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\LeafApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lists recent leaf program applications, for reviewing them until the
 * administrator pages are built (milestone 10). The output contains donors'
 * personal contact details: never paste it into issues, chat, or the repository.
 */
class ListLeafApplications extends Command
{
    use ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'leaves:applications
        {--days=30 : Show applications from this many days back}
        {--csv : Output CSV, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List recent leaf program applications';

    /**
     * Labels for the fields shown for each application, in order.
     */
    private const LABELS = [
        'Type',
        'Name',
        'Email',
        'Phone',
        'Address',
        'Estimated bags',
        'Bags ready',
        'Heard about us',
        'Questions',
    ];

    public function handle(): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $applications = LeafApplication::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->get();

        if ($this->option('csv')) {
            $this->writeCsv(
                ['ID', 'Applied', ...self::LABELS],
                $applications->map(fn (LeafApplication $application) => [
                    $application->id,
                    $this->localTime($application->created_at),
                    ...array_values($this->fields($application)),
                ]),
            );

            return self::SUCCESS;
        }

        if ($applications->isEmpty()) {
            $this->components->info("No applications in the last {$days} days.");

            return self::SUCCESS;
        }

        foreach ($applications as $application) {
            $this->writeRecord(
                "<options=bold>#{$application->id}</> " . $this->localTime($application->created_at),
                $this->fields($application),
            );
        }

        $this->newLine();
        $count = $applications->count();
        $this->components->info("{$count} " . Str::plural('application', $count) . " in the last {$days} days.");

        return self::SUCCESS;
    }

    /**
     * An application's fields, keyed by LABELS.
     *
     * @return array<string, string>
     */
    private function fields(LeafApplication $application): array
    {
        return array_combine(self::LABELS, [
            $application->type === LeafApplication::TYPE_PICKUP ? 'Pickup' : 'Drop-off',
            $application->name,
            $application->email,
            $application->phone,
            $application->address,
            (string) $application->estimated_bag_count,
            $application->ready_now ? 'Now' : $application->ready_date->format('D, M j, Y'),
            (string) $application->referral_source,
            (string) $application->questions,
        ]);
    }
}
