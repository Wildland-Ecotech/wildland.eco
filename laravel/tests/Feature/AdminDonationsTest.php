<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin donation pages: /admin/donations, its CSV download, and one
 * donation in full.
 */
class AdminDonationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera', 'email' => 'admin@example.com']);
    }

    /**
     * A received donation from a new donor with this name, paid $daysAgo days
     * ago, with any fields overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function donation(string $name, int $daysAgo = 3, array $attributes = []): Donation
    {
        $donor = User::factory()->create(['name' => $name]);

        return Donation::factory()->for($donor)->create(['paid_at' => now()->subDays($daysAgo), ...$attributes]);
    }

    /**
     * A received payment of a new monthly or annual donation, paid $daysAgo
     * days ago.
     */
    private function recurringDonation(string $name, string $interval, int $daysAgo = 3): Donation
    {
        $donation = $this->donation($name, $daysAgo);
        $subscription = Subscription::factory()->for($donation->user)->create(['interval' => $interval]);
        $donation->update(['subscription_id' => $subscription->id]);

        return $donation;
    }

    /**
     * The CSV download for the query string, parsed into rows.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<int, string>>
     */
    private function csvRows(array $query = []): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $this->actingAs($this->admin)->get(route('admin.donations.csv', $query))->getContent());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_only_signed_in_admins_can_see_donations(): void
    {
        $donation = $this->donation('Jamie Lee');
        $pages = ['/admin/donations', '/admin/donations/csv', "/admin/donations/{$donation->id}"];

        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }
    }

    public function test_lists_received_donations_with_a_link_to_each(): void
    {
        $donation = $this->donation('Jamie Lee', attributes: ['amount_cents' => 125000]);
        $this->donation('Failed Card', attributes: ['status' => Donation::STATUS_FAILED, 'paid_at' => null, 'fee_cents' => null]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/donations')
            ->assertOk()
            ->assertSee('1 donation received in the last 30 days.')
            ->assertSee('Oct 18, 7:30 PM')
            ->assertSee('Jamie Lee')
            ->assertSee('$1,250.00')
            // The ID is the link, rather than a separate View column
            ->assertSee('<a class="record-link" href="' . route('admin.donations.show', $donation->id) . '">' . $donation->id . '</a>', false)
            ->assertDontSee('>View</a>', false)
            ->assertDontSee('Failed Card')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_each_donation_has_a_short_line_for_phones(): void
    {
        $this->donation('Jamie Lee');
        $this->donation('Alex Kim', daysAgo: 365);

        // Date, donor, and amount, with the year only when it isn't this year
        $this->actingAs($this->admin)
            ->get('/admin/donations?period=all')
            ->assertSee('<summary>Oct 18 · Jamie Lee · $50.00</summary>', false)
            ->assertSee('<summary>Oct 21, 2025 · Alex Kim · $50.00</summary>', false)
            // The table's time also has the year only when it isn't this year
            ->assertSee('Oct 21, 2025, 7:30 PM');
    }

    public function test_pending_and_failed_payments_are_included_on_request(): void
    {
        $this->donation('Failed Card', attributes: ['status' => Donation::STATUS_FAILED, 'paid_at' => null, 'fee_cents' => null]);

        $this->actingAs($this->admin)
            ->get('/admin/donations?all=1')
            ->assertSee('Failed Card')
            ->assertSee('1 payment in the last 30 days.')
            ->assertSee('name="all" value="1" checked', false);
    }

    public function test_types_and_periods_narrow_the_list(): void
    {
        $this->donation('One Time');
        $this->recurringDonation('Every Month', Subscription::INTERVAL_MONTH);
        $this->recurringDonation('Every Year', Subscription::INTERVAL_YEAR, daysAgo: 200);

        $this->actingAs($this->admin)
            ->get('/admin/donations?type[]=monthly')
            ->assertSee('Every Month')
            ->assertDontSee('One Time')
            ->assertSee('1 monthly donation received in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/donations?type[]=one-time&type[]=annual&period=365')
            ->assertSee('One Time')
            ->assertSee('Every Year')
            ->assertDontSee('Every Month')
            ->assertSee('2 one-time and annual donations received in the last 365 days.');

        $this->actingAs($this->admin)
            ->get('/admin/donations?period=all')
            ->assertSee('3 donations received to date.');
    }

    public function test_unexpected_filters_fall_back_to_the_defaults(): void
    {
        $this->donation('Jamie Lee');

        $this->actingAs($this->admin)
            ->get('/admin/donations?period=7&type[]=weekly&all=yes')
            ->assertOk()
            ->assertSee('1 donation received in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/donations?type=monthly&period[]=all')
            ->assertOk()
            ->assertSee('1 donation received in the last 30 days.');
    }

    public function test_says_when_there_are_no_donations(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/donations')
            ->assertOk()
            ->assertSee('No donations received in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/donations?period=all')
            ->assertSee('No donations received yet.');
    }

    public function test_the_csv_download_has_every_detail_for_the_same_filters(): void
    {
        $this->donation('=HYPERLINK("https://example.com")', attributes: ['gateway_payment_id' => 'pi_onetime']);
        $this->recurringDonation('Every Month', Subscription::INTERVAL_MONTH);

        $this->actingAs($this->admin)
            ->get('/admin/donations?type[]=one-time')
            // Escaped as it is in the page's HTML
            ->assertSee(route('admin.donations.csv', ['period' => '30', 'type' => ['one-time']]));

        $this->actingAs($this->admin)
            ->get(route('admin.donations.csv', ['type' => ['one-time']]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('donations-2026-10-21.csv');

        $rows = $this->csvRows(['type' => ['one-time']]);

        $this->assertSame(['ID', 'Payment ID', 'Date', 'Type', 'Status', 'Name', 'Email', 'Amount', 'Stripe fee', 'Refunded', 'Net', 'Designation', 'Currency'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('pi_onetime', $rows[1][1]);
        // Kept from running as a formula in a spreadsheet
        $this->assertSame('\'=HYPERLINK("https://example.com")', $rows[1][5]);
        $this->assertSame('50.00', $rows[1][7]);
    }

    public function test_shows_one_donation_in_full_by_id_or_stripe_payment_id(): void
    {
        $donation = $this->donation('Jamie Lee', attributes: ['gateway_payment_id' => 'pi_onetime', 'refunded_cents' => 1000]);

        foreach (["/admin/donations/{$donation->id}", '/admin/donations/pi_onetime'] as $page) {
            $this->actingAs($this->admin)
                ->get($page)
                ->assertOk()
                ->assertSee("Donation {$donation->id}")
                ->assertSee('pi_onetime')
                ->assertSee('Sun, Oct 18, 2026 7:30 PM MDT')
                ->assertSee($donation->user->email)
                // $50.00 less $10.00 refunded and Stripe's $1.75 fee
                ->assertSee('$38.25')
                ->assertSee('General Fund')
                ->assertDontSee('was deleted on');
        }
    }

    public function test_a_fee_not_recorded_yet_is_pointed_out(): void
    {
        $donation = $this->donation('Jamie Lee', attributes: ['fee_cents' => null]);

        $this->actingAs($this->admin)
            ->get("/admin/donations/{$donation->id}")
            ->assertSee('Not recorded yet')
            // Plain text in the view, so its apostrophes aren't escaped
            ->assertSee("Stripe's fee isn't recorded yet.", false);
    }

    public function test_a_deleted_donation_is_shown_and_marked_as_deleted(): void
    {
        $donation = $this->donation('Jamie Lee');
        $donation->delete();

        $this->actingAs($this->admin)
            ->get('/admin/donations')
            ->assertDontSee('Jamie Lee');

        $this->actingAs($this->admin)
            ->get("/admin/donations/{$donation->id}")
            ->assertOk()
            ->assertSee('This donation was deleted on Wed, Oct 21, 2026 7:30 PM MDT');
    }

    public function test_an_unknown_donation_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/donations/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/donations/pi_missing')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/donations/abc')->assertNotFound();
    }

    public function test_the_admin_pages_link_to_each_other(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/donations')
            ->assertSee('href="' . route('admin') . '"', false)
            ->assertSee('aria-current="page">Donations</a>', false)
            ->assertSee('Sign Out');

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSee('Signed in as')
            ->assertSee('aria-current="page">Dashboard</a>', false);
    }
}
