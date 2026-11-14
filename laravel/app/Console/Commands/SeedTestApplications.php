<?php

namespace App\Console\Commands;

use App\Models\LeafApplication;
use App\Models\User;
use App\Services\ContactLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates made-up leaf applications for local QA testing, such as trying
 * the Map without entering applications by hand. Each is linked like a
 * real application (ContactLinker), making a new contact, and its address
 * gets a random map location in Fort Collins, set as the first admin, so
 * no geocoding is needed. Names and street addresses come from Faker, a dev
 * package, with example.com emails. It runs only on an admin's computer
 * (the `local` environment, or `testing`); on a server it refuses.
 */
class SeedTestApplications extends Command
{
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

    /**
     * The box the map locations fall in, roughly Fort Collins.
     */
    public const LATITUDES = [40.515, 40.615];

    public const LONGITUDES = [-105.135, -105.015];

    /**
     * Fort Collins ZIP codes, for the addresses.
     */
    public const ZIP_CODES = ['80521', '80524', '80525', '80526', '80528'];

    public function handle(ContactLinker $contacts): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Test applications can only be created on an admin’s computer, never on a server.');

            return self::FAILURE;
        }

        $count = (string) $this->argument('count');

        if (! ctype_digit($count) || (int) $count < 1 || (int) $count > self::MAX_COUNT) {
            $this->components->error('Enter how many applications to create, from 1 to ' . self::MAX_COUNT . '.');

            return self::INVALID;
        }

        $admin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if ($admin === null) {
            $this->components->error('Create an admin account first, with users:admin, so the map locations have someone to be set by.');

            return self::FAILURE;
        }

        for ($i = 0; $i < (int) $count; $i++) {
            $application = $this->application();

            $contacts->link($application);

            $application->refresh()->contact?->address()?->locate(
                fake()->latitude(...self::LATITUDES),
                fake()->longitude(...self::LONGITUDES),
                $admin,
            );
        }

        $this->components->info("Created {$count} test " . Str::plural('application', (int) $count) . ', each with a new contact and map location.');

        return self::SUCCESS;
    }

    /**
     * A made-up application: mostly pickups, 3 to 60 bags, ready now or
     * within three weeks.
     */
    private function application(): LeafApplication
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $readyNow = fake()->boolean(70);

        return LeafApplication::create([
            'type' => fake()->boolean(75) ? LeafApplication::TYPE_PICKUP : LeafApplication::TYPE_DROP_OFF,
            'name' => "{$first} {$last}",
            // A random number keeps each email and phone number new, so no
            // application is flagged as sharing one with another contact
            'email' => Str::slug("{$first} {$last}", '.') . fake()->numberBetween(1000, 9999) . '@example.com',
            'phone' => '970-555-' . fake()->numerify('####'),
            'address' => fake()->buildingNumber() . ' ' . fake()->streetName() . ', Fort Collins, CO ' . fake()->randomElement(self::ZIP_CODES),
            'estimated_bag_count' => fake()->numberBetween(3, 60),
            'ready_now' => $readyNow,
            'ready_date' => $readyNow ? null : now()->addDays(fake()->numberBetween(1, 21))->toDateString(),
            'terms_accepted_at' => now(),
        ]);
    }
}
