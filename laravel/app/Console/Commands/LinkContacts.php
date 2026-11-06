<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Models\LeafApplication;
use App\Services\ContactLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Links leaf applications that aren't linked to a contact yet, the same way
 * new applications are linked (ContactLinker): run once per environment for
 * the applications made before contacts existed, and any time to pick up an
 * application whose linking failed. Oldest first, so each contact ends up
 * with the details from their newest application. Deleted applications are
 * left out. Safe to run any number of times.
 *
 * --dry-run does everything inside a transaction that is rolled back, so it
 * shows exactly what would happen without saving anything. The output names
 * applicants: never paste it into issues, chat, or the repository.
 */
class LinkContacts extends Command
{
    use ListsRecords;

    /**
     * @var string
     */
    protected $signature = 'contacts:link
        {--dry-run : Show what would happen without saving anything}';

    /**
     * @var string
     */
    protected $description = 'Link leaf applications that have no contact yet to their contacts';

    /**
     * How each outcome is named in the summary, in order.
     */
    public const OUTCOME_LABELS = [
        ContactLinker::CREATED => 'New contacts',
        ContactLinker::LINKED => 'Linked to an existing contact',
        ContactLinker::CONFLICT => 'Not linked',
        'failed' => 'Failed',
    ];

    public const REVIEW_HEADERS = ['ID', 'Applied', 'Name', 'Why'];

    public function handle(ContactLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $applications = LeafApplication::query()
            ->whereNull('contact_id')
            ->whereNull('contact_review')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($applications->isEmpty()) {
            $this->components->info('Every application is already linked to a contact or waiting for review.');

            return self::SUCCESS;
        }

        $counts = array_fill_keys(array_keys(self::OUTCOME_LABELS), 0);
        $review = [];
        $failures = [];

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($applications as $application) {
                try {
                    $outcome = $linker->link($application);
                } catch (Throwable $exception) {
                    report($exception);
                    $counts['failed']++;
                    $failures[] = "Application {$application->id}: {$exception->getMessage()}";

                    continue;
                }

                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

                if ($application->contact_review !== null) {
                    $review[] = [
                        $application->id,
                        $this->tableTime($application->created_at),
                        $application->name,
                        collect($application->contact_review)->map(fn (string $reason) => LeafApplication::REVIEW_LABELS[$reason])->implode('; '),
                    ];
                }
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->table(
            [$dryRun ? 'Would be' : 'Applications', 'Count'],
            collect(self::OUTCOME_LABELS)->map(fn (string $label, string $outcome) => [$label, $counts[$outcome]])->values()->all(),
        );

        if ($review !== []) {
            $this->components->warn(count($review) . ($dryRun ? ' would need' : ' need') . ' an admin to look:');
            $this->writeTable(self::REVIEW_HEADERS, $review);
        }

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        if ($failures !== []) {
            $this->components->error(count($failures) . ' failed; details are also in the log. Running contacts:link again retries them.');
        }

        if ($dryRun) {
            $this->components->info('Dry run: nothing was saved. Run contacts:link without --dry-run to link them.');
        } else {
            $this->components->info(count($applications) - count($failures) . ' of ' . count($applications) . ' applications linked or flagged.');
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
