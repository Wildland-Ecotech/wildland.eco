<?php

namespace App\Console\Commands;

use App\Console\Traits\CreatesTestRecords;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\User;
use App\Services\ContactLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Creates made-up drop-offs needing attention for local QA testing, like
 * the ones logged before drop-off sites were recorded: drop-offs with no
 * drop-off site, each completing a new contact's drop-off application, and
 * drop-offs with no contact yet at the drop-off sites. Two test drop-off
 * sites are added when there are fewer than two. Contacts and sites get
 * random map locations in Fort Collins, and entries are logged by the
 * first admin, within the last three weeks (CreatesTestRecords). It runs
 * only on an admin's computer (the `local` environment, or `testing`); on a
 * server it refuses.
 */
class SeedTestDropOffs extends Command
{
    use CreatesTestRecords;

    /**
     * @var string
     */
    protected $signature = 'leaves:seed-drop-offs
        {count=12 : How many drop-offs with no drop-off site to create, up to 200}
        {--unknown=3 : How many drop-offs with no contact yet to create at the drop-off sites, up to 200}';

    /**
     * @var string
     */
    protected $description = 'Create made-up drop-offs needing attention for local testing';

    public const MAX_COUNT = 200;

    /**
     * The drop-off sites added when there are fewer than two.
     */
    public const SITES = ['North Test Drop-off Site', 'South Test Drop-off Site'];

    /**
     * Bag brands, given to about four in five entries.
     */
    public const BRANDS = ['Ace Hardware', 'Home Depot', "Lowe's", 'Walmart'];

    public function handle(ContactLinker $contacts): int
    {
        if (! $this->onAdminComputer('Test drop-offs')) {
            return self::FAILURE;
        }

        $count = $this->count((string) $this->argument('count'), 'Enter how many drop-offs with no drop-off site to create, from 0 to ' . self::MAX_COUNT . '.');
        $unknown = $this->count((string) $this->option('unknown'), 'Enter how many drop-offs with no contact to create with --unknown, from 0 to ' . self::MAX_COUNT . '.');

        if ($count === null || $unknown === null) {
            return self::INVALID;
        }

        if ($count + $unknown === 0) {
            $this->components->error('Enter at least one drop-off to create.');

            return self::INVALID;
        }

        $admin = $this->testAdmin();

        if ($admin === null) {
            return self::FAILURE;
        }

        $sites = Contact::query()->dropOffSites()->get();
        $added = [];

        foreach (self::SITES as $name) {
            if ($sites->count() >= 2) {
                break;
            }

            $site = Contact::create(['name' => $name, 'is_drop_off_site' => true]);
            $this->locateRandomly($site->moveTo($this->testAddress()), $admin);
            $sites->push($site);
            $added[] = $name;
        }

        if ($added !== []) {
            $this->components->info('Added ' . Str::plural('test drop-off site', count($added)) . ': ' . implode(' and ', $added) . '.');
        }

        for ($i = 0; $i < $count; $i++) {
            $this->dropOffWithNoSite($contacts, $admin);
        }

        for ($i = 0; $i < $unknown; $i++) {
            $this->dropOffWithNoContact($sites, $admin);
        }

        $this->components->info('Created ' . implode(', and ', array_filter([
            $count > 0 ? "{$count} " . Str::plural('drop-off', $count) . ' with no drop-off site, ' . ($count === 1 ? 'for a new contact' : 'each for a new contact') : null,
            $unknown > 0 ? "{$unknown} " . Str::plural('drop-off', $unknown) . ' with no contact at the drop-off sites' : null,
        ])) . '.');

        return self::SUCCESS;
    }

    /**
     * A whole number from 0 to the most, or null with the error.
     */
    private function count(string $value, string $error): ?int
    {
        if (! ctype_digit($value) || (int) $value > self::MAX_COUNT) {
            $this->components->error($error);

            return null;
        }

        return (int) $value;
    }

    /**
     * A drop-off with no drop-off site, like those logged before sites were
     * recorded, for a new contact's drop-off application, applied for a few
     * days before. Logging it marks the application complete.
     */
    private function dropOffWithNoSite(ContactLinker $contacts, User $admin): void
    {
        $happenedAt = $this->recently();

        $application = $this->testApplication(LeafApplication::TYPE_DROP_OFF);
        $application->forceFill([
            'ready_now' => true,
            'ready_date' => null,
            'created_at' => $happenedAt->copy()->subDays(fake()->numberBetween(1, 7)),
        ])->save();

        $contacts->link($application);
        $contact = $application->refresh()->contact;
        $this->locateRandomly($contact->address(), $admin);

        $entry = $contact->activities()->make([
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => $happenedAt,
            'bag_count' => max(1, $application->estimated_bag_count + fake()->numberBetween(-3, 3)),
            'bag_brand' => $this->brand(),
            'leaf_application_id' => $application->id,
        ]);
        $entry->forceFill(['logged_by' => $admin->id])->save();
    }

    /**
     * A drop-off with no contact yet, at one of the drop-off sites.
     *
     * @param  Collection<int, Contact>  $sites
     */
    private function dropOffWithNoContact(Collection $sites, User $admin): void
    {
        $entry = new ContactActivity([
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => $this->recently(),
            'bag_count' => fake()->numberBetween(1, 20),
            'bag_brand' => $this->brand(),
            'drop_off_site_id' => $sites->random()->id,
        ]);
        $entry->forceFill(['logged_by' => $admin->id])->save();
    }

    /**
     * A time within the last three weeks, at least an hour ago.
     */
    private function recently(): Carbon
    {
        return now()->subMinutes(fake()->numberBetween(60, 21 * 24 * 60));
    }

    /**
     * One of the brands, or no brand for about one in five entries.
     */
    private function brand(): ?string
    {
        return fake()->boolean(80) ? fake()->randomElement(self::BRANDS) : null;
    }
}
