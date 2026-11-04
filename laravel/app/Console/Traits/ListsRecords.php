<?php

namespace App\Console\Traits;

use App\Reports\Traits\FormatsTimes;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\TableStyle;

/**
 * Shared parts of the commands that list records for review: the --days
 * option, a table of records or one block of fields per record, and times in
 * Fort Collins time (FormatsTimes). For classes extending
 * Illuminate\Console\Command.
 */
trait ListsRecords
{
    use FormatsTimes;

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
     * submitted text cannot add console formatting tags. Columns listed in
     * $rightAligned, by index, line up on the right, for amounts.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $rightAligned
     */
    protected function writeTable(array $headers, iterable $rows, array $rightAligned = []): void
    {
        $escaped = [];

        foreach ($rows as $row) {
            $escaped[] = array_map(fn (mixed $value) => OutputFormatter::escape((string) $value), $row);
        }

        $this->table($headers, $escaped, 'default', self::rightAlignedColumns($rightAligned));
    }

    /**
     * Column styles that line the given columns up on the right, for
     * table() and for expectsTable() in tests.
     *
     * @param  array<int, int>  $columns
     * @return array<int, TableStyle>
     */
    public static function rightAlignedColumns(array $columns): array
    {
        $styles = [];

        foreach ($columns as $column) {
            $styles[$column] = (new TableStyle)->setPadType(STR_PAD_LEFT);
        }

        return $styles;
    }
}
