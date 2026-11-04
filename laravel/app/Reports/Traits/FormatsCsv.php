<?php

namespace App\Reports\Traits;

/**
 * CSV for reports, for importing into a spreadsheet: written to the console
 * by commands (App\Console\Traits\WritesCsv), and downloaded from admin
 * pages.
 */
trait FormatsCsv
{
    /**
     * Rows as CSV text with a header row.
     *
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function csv(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->spreadsheetSafe(...), $row), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Anyone can submit our forms, so a value starting with =, +, -, or @
     * could run as a formula when the CSV is opened in a spreadsheet. A
     * leading apostrophe makes spreadsheets treat it as text. A plain
     * negative number, such as "-1.75", is left as it is so spreadsheets can
     * add it up; nothing else gets through.
     */
    private function spreadsheetSafe(mixed $value): mixed
    {
        if (is_string($value) && ! preg_match('/^-\d+(\.\d+)?\z/', $value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }
}
