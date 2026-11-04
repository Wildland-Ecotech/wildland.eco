<?php

namespace App\Console\Traits;

use App\Reports\Traits\FormatsCsv;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CSV output for report commands, for importing into a spreadsheet. For
 * classes extending Illuminate\Console\Command.
 */
trait WritesCsv
{
    use FormatsCsv;

    /**
     * Write rows as CSV with a header row. The CSV is written raw and in one
     * piece, so values are never read as console formatting tags.
     *
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function writeCsv(array $header, iterable $rows): void
    {
        $this->output->write($this->csv($header, $rows), false, OutputInterface::OUTPUT_RAW);
    }
}
