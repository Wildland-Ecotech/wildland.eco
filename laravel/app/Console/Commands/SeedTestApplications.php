<?php

namespace App\Console\Commands;

use App\Console\Traits\CreatesTestRecords;
use App\Services\ContactLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates made-up leaf applications for local QA testing, such as trying
 * the Map without entering applications by hand. Each is linked like a
 * real application (ContactLinker), making a new contact, and its address
 * gets a random map location in Fort Collins, set as the first admin, so
 * no geocoding is needed (CreatesTestRecords). It runs only on an admin's
 * computer (the `local` environment, or `testing`); on a server it refuses.
 */
class SeedTestApplications extends Command
{
    use CreatesTestRecords;

    /**
     * @var string
     */
    protected $signature = 'leaves:seed
        {count=10 : How many applications to create, up to 500}';

    /**
     * @var string
     */
    protected $description = 'Create made-up leaf applications for local testing';

    public const MAX_COUNT = 500;

    public function handle(ContactLinker $contacts): int
    {
        if (! $this->onAdminComputer('Test applications')) {
            return self::FAILURE;
        }

        $count = (string) $this->argument('count');

        if (! ctype_digit($count) || (int) $count < 1 || (int) $count > self::MAX_COUNT) {
            $this->components->error('Enter how many applications to create, from 1 to ' . self::MAX_COUNT . '.');

            return self::INVALID;
        }

        $admin = $this->testAdmin();

        if ($admin === null) {
            return self::FAILURE;
        }

        for ($i = 0; $i < (int) $count; $i++) {
            $application = $this->testApplication();

            $contacts->link($application);

            $this->locateRandomly($application->refresh()->contact?->address(), $admin);
        }

        $this->components->info("Created {$count} test " . Str::plural('application', (int) $count) . ', each with a new contact and map location.');

        return self::SUCCESS;
    }
}
