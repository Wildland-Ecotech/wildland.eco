<?php

namespace Tests\Feature;

use App\Models\LeafApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListLeafApplicationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));
    }

    /**
     * Save an application, with any fields overridden, made the given number of days ago.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function application(array $overrides = [], int $daysAgo = 0): LeafApplication
    {
        $application = LeafApplication::create(array_merge([
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
        ], $overrides));

        $application->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $application;
    }

    /**
     * Run the command with --csv and parse its output into rows.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, string>>
     */
    private function csvRows(array $options = []): array
    {
        Artisan::call('leaves:applications', ['--csv' => true, ...$options]);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, Artisan::output());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_lists_recent_applications_with_their_details(): void
    {
        $this->application();

        $this->artisan('leaves:applications')
            ->expectsOutputToContain('Wed, Oct 21, 2026 7:30 PM MDT')
            ->expectsOutputToContain('Pickup')
            ->expectsOutputToContain('Test Donor')
            ->expectsOutputToContain('(970) 555-0123')
            ->expectsOutputToContain('Tue, Oct 27, 2026')
            ->expectsOutputToContain('Do you take pine needles?')
            ->expectsOutputToContain('1 application in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_bags_ready_now_are_shown_as_now(): void
    {
        $this->application(['type' => LeafApplication::TYPE_DROP_OFF, 'ready_now' => true, 'ready_date' => null]);

        $this->artisan('leaves:applications')
            ->expectsOutputToContain('Drop-off')
            ->expectsOutputToContain('Now')
            ->assertSuccessful();
    }

    public function test_only_applications_within_the_days_option_are_listed(): void
    {
        $this->application(['name' => 'Recent Donor'], daysAgo: 5);
        $this->application(['name' => 'Older Donor'], daysAgo: 40);

        $this->artisan('leaves:applications')
            ->expectsOutputToContain('Recent Donor')
            ->doesntExpectOutputToContain('Older Donor')
            ->assertSuccessful();

        $this->artisan('leaves:applications', ['--days' => 60])
            ->expectsOutputToContain('Older Donor')
            ->assertSuccessful();
    }

    public function test_says_when_there_are_no_applications(): void
    {
        $this->artisan('leaves:applications')
            ->expectsOutputToContain('No applications in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_rejects_an_invalid_days_option(): void
    {
        $this->artisan('leaves:applications', ['--days' => 'week'])
            ->assertExitCode(2);
    }

    public function test_csv_has_a_header_and_one_row_per_application_oldest_first(): void
    {
        $this->application(['name' => 'Second Donor'], daysAgo: 1);
        $this->application(['name' => 'First Donor', 'questions' => "Two lines,\nwith a comma"], daysAgo: 2);

        $rows = $this->csvRows();

        $this->assertSame(
            ['ID', 'Applied', 'Type', 'Name', 'Email', 'Phone', 'Address', 'Estimated bags', 'Bags ready', 'Heard about us', 'Questions'],
            $rows[0],
        );
        $this->assertCount(3, $rows);
        $this->assertSame('First Donor', $rows[1][3]);
        $this->assertSame("Two lines,\nwith a comma", $rows[1][10]);
        $this->assertSame('Second Donor', $rows[2][3]);
    }

    public function test_csv_values_cannot_run_as_spreadsheet_formulas(): void
    {
        $this->application([
            'name' => '=HYPERLINK("https://example.com")',
            'phone' => '+1 970 555 0123',
            'questions' => '@SUM(A1:A2)',
        ]);

        $row = $this->csvRows()[1];

        $this->assertSame("'=HYPERLINK(\"https://example.com\")", $row[3]);
        $this->assertSame("'+1 970 555 0123", $row[5]);
        $this->assertSame("'@SUM(A1:A2)", $row[10]);
    }
}
