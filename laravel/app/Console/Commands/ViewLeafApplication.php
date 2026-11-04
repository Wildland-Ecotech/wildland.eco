<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Models\LeafApplication;
use App\Reports\Traits\DescribesLeafApplications;
use Illuminate\Console\Command;

/**
 * Shows one leaf program application in full, by its ID from
 * leaves:applications. A deleted application is shown too, marked as
 * deleted, since it was asked for by ID. The output contains the donor's
 * personal contact details: never paste it into issues, chat, or the
 * repository.
 */
class ViewLeafApplication extends Command
{
    use DescribesLeafApplications, ListsRecords;

    /**
     * @var string
     */
    protected $signature = 'leaves:view
        {application : The application\'s ID from leaves:applications}';

    /**
     * @var string
     */
    protected $description = 'Show one leaf program application in full';

    public function handle(): int
    {
        $id = (string) $this->argument('application');

        if (! ctype_digit($id)) {
            $this->components->error('Enter an application ID from leaves:applications, such as 12.');

            return self::INVALID;
        }

        $application = LeafApplication::withTrashed()->find((int) $id);

        if (! $application) {
            $this->components->error("No application has the ID {$id}. Check the ID in leaves:applications, with --days for older ones.");

            return self::FAILURE;
        }

        $this->writeRecord("<options=bold>Application {$application->id}</>", [
            'Applied' => $this->localTime($application->created_at),
            ...$this->leafApplicationFields($application),
            'Terms accepted' => $this->localTime($application->terms_accepted_at),
        ]);

        if ($application->trashed()) {
            $this->newLine();
            $this->components->warn('This application was deleted on ' . $this->localTime($application->deleted_at) . ', so leaves:applications leaves it out.');
        }

        return self::SUCCESS;
    }
}
