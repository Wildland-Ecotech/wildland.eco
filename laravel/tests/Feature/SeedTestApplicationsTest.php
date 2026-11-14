<?php

namespace Tests\Feature;

use App\Console\Commands\SeedTestApplications;
use App\Models\Contact;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * leaves:seed, which creates made-up applications for local testing,
 * each with a new contact and map location.
 */
class SeedTestApplicationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_applications_each_with_a_new_contact_and_location(): void
    {
        $admin = User::factory()->admin()->withPassword()->create();

        $this->artisan('leaves:seed', ['count' => '5'])
            ->expectsOutputToContain('Created 5 test applications, each with a new contact and map location.')
            ->assertSuccessful();

        $this->assertSame(5, LeafApplication::count());
        $this->assertSame(5, Contact::count());

        foreach (LeafApplication::with('contact')->get() as $application) {
            $this->assertNull($application->contact_review, $application->name);
            $this->assertStringEndsWith('@example.com', $application->email);
            $this->assertMatchesRegularExpression('/^970-555-\d{4}$/', $application->phone);
            $this->assertStringContainsString(', Fort Collins, CO 805', $application->address);
            $this->assertContains($application->type, LeafApplication::TYPES);
            $this->assertSame(LeafApplication::STATUS_PENDING, $application->status);

            $address = $application->contact->address();
            $this->assertNotNull($address, $application->name);
            $this->assertSame($admin->id, $address->located_by);
            $this->assertGreaterThanOrEqual(SeedTestApplications::LATITUDES[0], $address->location['latitude']);
            $this->assertLessThanOrEqual(SeedTestApplications::LATITUDES[1], $address->location['latitude']);
            $this->assertGreaterThanOrEqual(SeedTestApplications::LONGITUDES[0], $address->location['longitude']);
            $this->assertLessThanOrEqual(SeedTestApplications::LONGITUDES[1], $address->location['longitude']);
        }
    }

    public function test_creates_ten_by_default(): void
    {
        User::factory()->admin()->withPassword()->create();

        $this->artisan('leaves:seed')
            ->expectsOutputToContain('Created 10 test applications')
            ->assertSuccessful();

        $this->assertSame(10, LeafApplication::count());
    }

    public function test_needs_a_count_from_1_to_500(): void
    {
        User::factory()->admin()->withPassword()->create();

        foreach (['0', '501', 'lots', '-3'] as $count) {
            $this->artisan('leaves:seed', ['count' => $count])
                ->expectsOutputToContain('Enter how many applications to create, from 1 to 500.')
                ->assertExitCode(SeedTestApplications::INVALID);
        }

        $this->assertSame(0, LeafApplication::count());
    }

    public function test_needs_an_admin_to_set_the_locations(): void
    {
        $this->artisan('leaves:seed', ['count' => '1'])
            ->expectsOutputToContain('Create an admin account first')
            ->assertFailed();

        $this->assertSame(0, LeafApplication::count());
    }

    public function test_refuses_on_a_server(): void
    {
        User::factory()->admin()->withPassword()->create();
        $this->app['env'] = 'production';

        $this->artisan('leaves:seed', ['count' => '1'])
            ->expectsOutputToContain('Test applications can only be created on an admin’s computer, never on a server.')
            ->assertFailed();

        $this->assertSame(0, LeafApplication::count());
    }
}
