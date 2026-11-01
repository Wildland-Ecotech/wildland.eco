<?php

namespace App\Console\Traits;

use Carbon\CarbonInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Shared parts of the commands that list records for review until the
 * administrator pages are built (milestone 10): the --days option, a table
 * of records or one block of fields per record, and times in Fort Collins
 * time. For classes extending Illuminate\Console\Command.
 */
trait ListsRecords
{
    /**
     * The --days option as a whole number of at least 1, or null after
     * showing an error.
     */
    protected function daysOption(): ?int
    {
        $days = $this->option('days');

        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->components->error('--days must be a whole number of at least 1.');

            return null;
        }

        return (int) $days;
    }

    /**
     * Write one record: its heading, then each field on its own line, with
     * a dash for an empty value. The heading may use console formatting tags;
     * field values are escaped, so submitted text cannot add any.
     *
     * @param  array<string, string>  $fields
     */
    protected function writeRecord(string $heading, array $fields): void
    {
        $this->newLine();
        $this->line($heading);

        foreach ($fields as $label => $value) {
            $this->line("  <fg=gray>{$label}:</> " . ($value === '' ? '—' : OutputFormatter::escape($value)));
        }
    }

    /**
     * Write records as a table, one row each. Values are escaped, so
     * submitted text cannot add console formatting tags.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function writeTable(array $headers, iterable $rows): void
    {
        $escaped = [];

        foreach ($rows as $row) {
            $escaped[] = array_map(fn (mixed $value) => OutputFormatter::escape((string) $value), $row);
        }

        $this->table($headers, $escaped);
    }

    /**
     * A time in Fort Collins time, by default such as "Wed, Oct 21, 2026
     * 7:30 PM MDT".
     */
    protected function localTime(CarbonInterface $time, string $format = 'D, M j, Y g:i A T'): string
    {
        return $time->copy()->setTimezone(config('organization.timezone'))->format($format);
    }
}
