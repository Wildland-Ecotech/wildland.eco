<?php

namespace App\Console\Traits;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * CSV output for report commands, for importing into a spreadsheet. For
 * classes extending Illuminate\Console\Command.
 */
trait WritesCsv
{
    /**
     * Write rows as CSV with a header row. The CSV is written raw and in one
     * piece, so values are never read as console formatting tags.
     *
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function writeCsv(array $header, iterable $rows): void
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->spreadsheetSafe(...), $row), escape: '');
        }

        rewind($handle);
        $this->output->write(stream_get_contents($handle), false, OutputInterface::OUTPUT_RAW);
        fclose($handle);
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
