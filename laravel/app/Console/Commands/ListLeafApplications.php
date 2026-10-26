<?php

namespace App\Console\Commands;

use App\Http\Requests\StoreLeafApplicationRequest;
use App\Models\LeafApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists recent leaf program applications, for reviewing them until the
 * administrator pages are built (milestone 10). The output contains donors'
 * personal contact details: never paste it into issues, chat, or the repository.
 */
class ListLeafApplications extends Command
{
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
        $days = $this->option('days');

        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->components->error('--days must be a whole number of at least 1.');

            return self::INVALID;
        }

        $applications = LeafApplication::query()
            ->where('created_at', '>=', now()->subDays((int) $days))
            ->orderBy('created_at')
            ->get();

        if ($this->option('csv')) {
            $this->output->write($this->csv($applications), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($applications->isEmpty()) {
            $this->components->info("No applications in the last {$days} days.");

            return self::SUCCESS;
        }

        foreach ($applications as $application) {
            $this->newLine();
            $this->line("<options=bold>#{$application->id}</> " . $this->appliedAt($application));

            foreach ($this->fields($application) as $label => $value) {
                // Escaped so submitted text cannot add console formatting tags
                $this->line("  <fg=gray>{$label}:</> " . ($value === '' ? '—' : OutputFormatter::escape($value)));
            }
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

    /**
     * When the application was made, in the leaf program's local time.
     */
    private function appliedAt(LeafApplication $application): string
    {
        return $application->created_at
            ->setTimezone(StoreLeafApplicationRequest::TIMEZONE)
            ->format('D, M j, Y g:i A T');
    }

    /**
     * All applications as CSV, one row each, oldest first.
     *
     * @param  Collection<int, LeafApplication>  $applications
     */
    private function csv(Collection $applications): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Applied', ...self::LABELS], escape: '');

        foreach ($applications as $application) {
            $row = [$application->id, $this->appliedAt($application), ...array_values($this->fields($application))];

            fputcsv($handle, array_map($this->spreadsheetSafe(...), $row), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Anyone can submit the form, so a value starting with =, +, -, or @ could
     * run as a formula when the CSV is opened in a spreadsheet. A leading
     * apostrophe makes spreadsheets treat it as text.
     */
    private function spreadsheetSafe(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }
}
