<?php

namespace Tests\Feature;

use App\Models\LeafApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `php artisan leaves:view`: one leaf program application in full.
 */
class ViewLeafApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));
    }

    /**
     * Save an application made now.
     */
    private function application(): LeafApplication
    {
        return LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Test Donor',
            'email' => 'donor@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 40,
            'ready_now' => false,
            'ready_date' => '2026-10-27',
            'referral_source' => 'A flyer',
            'questions' => 'Do you take pine needles?',
            'terms_accepted_at' => now(),
        ]);
    }

    public function test_shows_an_application_in_full(): void
    {
        $application = $this->application();

        $this->artisan('leaves:view', ['application' => (string) $application->id])
            ->expectsOutputToContain("Application {$application->id}")
            ->expectsOutputToContain('Applied: Wed, Oct 21, 2026 7:30 PM MDT')
            ->expectsOutputToContain('Type: Pickup')
            ->expectsOutputToContain('Name: Test Donor')
            ->expectsOutputToContain('Email: donor@example.com')
            ->expectsOutputToContain('Phone: 970-555-0123')
            ->expectsOutputToContain('Address: 123 Example St, Fort Collins, CO 80521')
            ->expectsOutputToContain('Estimated bags: 40')
            ->expectsOutputToContain('Bags ready: Tue, Oct 27, 2026')
            ->expectsOutputToContain('Heard about us: A flyer')
            ->expectsOutputToContain('Questions: Do you take pine needles?')
            ->expectsOutputToContain('Terms accepted: Wed, Oct 21, 2026 7:30 PM MDT')
            ->assertSuccessful();
    }

    public function test_a_deleted_application_is_shown_and_marked_as_deleted(): void
    {
        $application = $this->application();
        $application->delete();

        $this->artisan('leaves:view', ['application' => (string) $application->id])
            ->expectsOutputToContain("Application {$application->id}")
            ->expectsOutputToContain('This application was deleted on Wed, Oct 21, 2026 7:30 PM MDT')
            ->assertSuccessful();
    }

    public function test_says_when_no_application_has_the_id(): void
    {
        $this->artisan('leaves:view', ['application' => '999999'])
            ->expectsOutputToContain('No application has the ID 999999.')
            ->assertFailed();
    }

    public function test_rejects_anything_but_an_id(): void
    {
        $this->artisan('leaves:view', ['application' => 'donor@example.com'])
            ->assertExitCode(2);
    }
}
