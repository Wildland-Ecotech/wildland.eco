<?php

namespace App\Console\Traits;

use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Made-up records for local QA testing, for the seed commands: applications
 * with Faker names and Fort Collins addresses, map locations in a box around
 * Fort Collins, and the checks that keep them off servers. Faker is a dev
 * package, so these only work on an admin's computer. For classes extending
 * Illuminate\Console\Command.
 */
trait CreatesTestRecords
{
    /**
     * The box the map locations fall in, roughly Fort Collins.
     */
    public const LATITUDES = [40.515, 40.615];

    public const LONGITUDES = [-105.135, -105.015];

    /**
     * Fort Collins ZIP codes, for the addresses.
     */
    public const ZIP_CODES = ['80521', '80524', '80525', '80526', '80528'];

    /**
     * Whether this is an admin's computer (the `local` environment, or
     * `testing`), or else an error saying what can't be created here.
     */
    protected function onAdminComputer(string $records): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $this->components->error("{$records} can only be created on an admin's computer, never on a server.");

        return false;
    }

    /**
     * The first admin account, to set map locations and log entries, or
     * null with an error saying to create one.
     */
    protected function testAdmin(): ?User
    {
        $admin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if ($admin === null) {
            $this->components->error('Create an admin account first, with users:admin, so the test records have someone to be set by.');
        }

        return $admin;
    }

    /**
     * A made-up application, a pickup or drop-off as given, or mostly
     * pickups: 3 to 60 bags, ready now or within three weeks.
     */
    protected function testApplication(?string $type = null): LeafApplication
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $readyNow = fake()->boolean(70);

        return LeafApplication::create([
            'type' => $type ?? (fake()->boolean(75) ? LeafApplication::TYPE_PICKUP : LeafApplication::TYPE_DROP_OFF),
            'name' => "{$first} {$last}",
            // A random number keeps each email and phone number new, so no
            // application is flagged as sharing one with another contact
            'email' => Str::slug("{$first} {$last}", '.') . fake()->numberBetween(1000, 9999) . '@example.com',
            'phone' => '970-555-' . fake()->numerify('####'),
            'address' => $this->testAddress(),
            'estimated_bag_count' => fake()->numberBetween(3, 60),
            'ready_now' => $readyNow,
            'ready_date' => $readyNow ? null : now()->addDays(fake()->numberBetween(1, 21))->toDateString(),
            'terms_accepted_at' => now(),
        ]);
    }

    /**
     * A made-up Fort Collins street address.
     */
    protected function testAddress(): string
    {
        return fake()->buildingNumber() . ' ' . fake()->streetName() . ', Fort Collins, CO ' . fake()->randomElement(self::ZIP_CODES);
    }

    /**
     * Put an address at a random map location in the box, set by the admin,
     * so no geocoding is needed.
     */
    protected function locateRandomly(?ContactDetail $address, User $admin): void
    {
        $address?->locate(fake()->latitude(...self::LATITUDES), fake()->longitude(...self::LONGITUDES), $admin);
    }
}
