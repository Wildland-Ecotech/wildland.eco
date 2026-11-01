<?php

namespace Tests\Feature;

use App\Console\Commands\ListAbandonedCheckouts;
use App\Models\Checkout;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan donations:abandoned`: checkouts donors left without paying.
 */
class ListAbandonedCheckoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->donor = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
    }

    /**
     * A checkout that expired without payment, started the given number of
     * days ago, with any fields overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function expired(int $daysAgo, array $attributes = [], ?User $donor = null): Checkout
    {
        return Checkout::factory()->for($donor ?? $this->donor)->expired()->create([
            'created_at' => now()->subDays($daysAgo),
            ...$attributes,
        ]);
    }

    /**
     * Run the command with --csv and parse its output into rows.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, string>>
     */
    private function csvRows(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('donations:abandoned', ['--csv' => true, ...$options]));

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

    /**
     * The checkout references listed in the CSV, in order.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function references(array $options = []): array
    {
        return array_column(array_slice($this->csvRows($options), 1), 0);
    }

    public function test_lists_checkouts_left_without_paying_in_a_table(): void
    {
        $this->expired(2, ['amount_cents' => 2000]);

        // Expired an hour ago, but the webhook hasn't said so yet
        Checkout::factory()->for($this->donor)->oneTime()->create([
            'amount_cents' => 5000,
            'created_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);

        // Not abandoned: still open, never reached Stripe, paid, or deleted
        Checkout::factory()->for($this->donor)->create();
        Checkout::factory()->for($this->donor)->create(['gateway_session_id' => null, 'expires_at' => null]);
        Checkout::factory()->for($this->donor)->completed()->create();
        $this->expired(1)->delete();

        $this->artisan('donations:abandoned')
            ->expectsTable(ListAbandonedCheckouts::HEADERS, [
                ['Oct 19, 2026 7:30 PM MDT', 'Jamie Rivera', 'Monthly', '$20.00', 'No'],
                ['Oct 20, 2026 7:30 PM MDT', 'Jamie Rivera', 'One-time', '$50.00', 'No'],
            ], 'default', ListAbandonedCheckouts::rightAlignedColumns(ListAbandonedCheckouts::AMOUNT_COLUMNS))
            ->expectsOutputToContain('2 abandoned checkouts in the last 30 days; 0 donated later.')
            ->assertSuccessful();
    }

    public function test_shows_whether_the_donor_donated_after_leaving(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();

        // Alex left, then came back and paid
        $cameBack = $this->expired(3, [], $alex);
        Donation::factory()->for($alex)->create(['paid_at' => now()->subDays(2)]);

        // Sam donated before leaving this checkout, and a later payment failed
        $didNot = $this->expired(3, [], $sam);
        Donation::factory()->for($sam)->create(['paid_at' => now()->subDays(5)]);
        Donation::factory()->for($sam)->failed()->create();

        $rows = array_slice($this->csvRows(), 1);

        $this->assertSame([$cameBack->reference, $didNot->reference], array_column($rows, 0));
        $this->assertSame(['Yes', 'No'], array_column($rows, 7));

        $this->artisan('donations:abandoned')
            ->expectsOutputToContain('2 abandoned checkouts in the last 30 days; 1 donated later.')
            ->assertSuccessful();
    }

    public function test_only_checkouts_within_the_days_option_are_listed(): void
    {
        $recent = $this->expired(5);
        $older = $this->expired(40);

        $this->assertSame([$recent->reference], $this->references());
        $this->assertSame([$older->reference, $recent->reference], $this->references(['--days' => 60]));
    }

    public function test_csv_has_every_detail_and_is_safe_to_open_in_a_spreadsheet(): void
    {
        $this->donor->update(['name' => '=HYPERLINK("https://example.com")']);
        $checkout = $this->expired(2, ['amount_cents' => 125000]);

        $rows = $this->csvRows();

        $this->assertSame(['Reference', 'Started', 'Name', 'Email', 'Type', 'Amount', 'Currency', 'Donated later'], $rows[0]);
        $this->assertSame(
            [$checkout->reference, 'Mon, Oct 19, 2026 7:30 PM MDT', "'=HYPERLINK(\"https://example.com\")", 'jamie@example.com', 'Monthly', '1250.00', 'USD', 'No'],
            $rows[1],
        );
    }

    public function test_says_when_there_are_no_abandoned_checkouts(): void
    {
        Checkout::factory()->for($this->donor)->completed()->create();

        $this->artisan('donations:abandoned')
            ->expectsOutputToContain('No abandoned checkouts in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_rejects_an_invalid_days_option(): void
    {
        $this->artisan('donations:abandoned', ['--days' => 'week'])
            ->assertExitCode(2);
    }
}
