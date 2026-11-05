<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\FormSubmission;
use App\Reports\FormSubmissionReport;
use App\Reports\Traits\DescribesFormSubmissions;
use Illuminate\Console\Command;

/**
 * Lists recent submissions of the donate and leaf application forms that
 * didn't succeed, so we can reach out to the people who made them: rejected,
 * expired, rate-limited, and failed submissions, submissions that stopped
 * without an outcome, and donors who went to pay but left the payment page
 * without finishing. The output contains visitors' names, emails, and form
 * details: never paste it into issues, chat, or the repository.
 */
class ListFormSubmissions extends Command
{
    use DescribesFormSubmissions, ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'submissions:list
        {--days=7 : Show submissions from this many days back}
        {--form= : Only one form: donation or leaf_application}
        {--all : Include submissions that succeeded}
        {--csv : Output CSV, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List recent form submissions that did not succeed';

    public function handle(FormSubmissionReport $report): int
    {
        $days = $this->daysOption();
        $form = $this->option('form');

        if ($days === null) {
            return self::INVALID;
        }

        if ($form !== null && ! in_array($form, FormSubmission::FORMS, true)) {
            $this->components->error('--form must be one of: ' . implode(', ', FormSubmission::FORMS) . '.');

            return self::INVALID;
        }

        $all = (bool) $this->option('all');
        $submissions = $report->list($days, $form === null ? [] : [$form], $all);

        if ($this->option('csv')) {
            $this->writeCsv($this->formSubmissionCsvHeader(), $submissions->map($this->formSubmissionCsvRow(...)));

            return self::SUCCESS;
        }

        if ($submissions->isEmpty()) {
            $this->components->info("No {$this->formSubmissionListName($all, 0)} in the last {$days} days.");

            return self::SUCCESS;
        }

        foreach ($submissions as $submission) {
            $this->writeRecord(
                "<options=bold>{$submission->reference}</> " . $this->localTime($submission->created_at),
                $this->formSubmissionFields($submission),
            );
        }

        $this->newLine();
        $count = $submissions->count();
        $this->components->info("{$count} {$this->formSubmissionListName($all, $count)} in the last {$days} days.");

        return self::SUCCESS;
    }
}
