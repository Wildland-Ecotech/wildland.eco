<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin donation reports: the totals' CSV download
 * (/admin/donations/totals/csv), whose totals are on the dashboard, and
 * abandoned checkouts (/admin/donations/abandoned), with its CSV download.
 */
class AdminDonationReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create();
        $this->donor = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
    }

    /**
     * A received donation paid at the given Fort Collins time. Times are
     * stored in UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function paid(string $localTime, int $amountCents, ?int $feeCents, array $attributes = []): Donation
    {
        return Donation::factory()->for($this->donor)->create([
            'paid_at' => Carbon::parse($localTime, 'America/Denver')->utc(),
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            ...$attributes,
        ]);
    }

    /**
     * A checkout that expired without payment, started $daysAgo days ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function expired(int $daysAgo, array $attributes = []): Checkout
    {
        return Checkout::factory()->for($this->donor)->expired()->create([
            'created_at' => now()->subDays($daysAgo),
            'amount_cents' => 5000,
            ...$attributes,
        ]);
    }

    public function test_only_signed_in_admins_can_download_totals(): void
    {
        $this->get('/admin/donations/totals/csv')->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();

        $this->actingAs($donor)->get('/admin/donations/totals/csv')->assertForbidden();
    }

    public function test_the_old_totals_page_goes_to_the_dashboard(): void
    {
        $this->get('/admin/donations/totals')->assertRedirect(route('login'));

        $this->actingAs($this->admin)
            ->get('/admin/donations/totals')
            ->assertRedirect('/admin');
    }

    public function test_the_csv_download_matches_the_command(): void
    {
        $this->paid('2026-01-15 10:00', 5000, 175);

        $response = $this->actingAs($this->admin)
            ->get('/admin/donations/totals/csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('donation-totals-2026-10-21.csv');

        $lines = explode("\n", trim($response->getContent()));

        $this->assertSame('Grouping,Period,Donations,Amount,Refunded,"Stripe fees",Net,Currency', $lines[0]);
        $this->assertSame('"Month over month","Jan 2026",1,50.00,0.00,1.75,48.25,USD', $lines[1]);
        $this->assertCount(5, $lines);
    }

    public function test_only_signed_in_admins_can_see_abandoned_checkouts(): void
    {
        $pages = ['/admin/donations/abandoned', '/admin/donations/abandoned/csv'];

        // Every page as a guest first, since signing in lasts for the rest of the test
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }
    }

    public function test_lists_abandoned_checkouts_and_whether_the_donor_came_back(): void
    {
        $this->expired(3);
        Donation::factory()->for($this->donor)->create(['paid_at' => now()->subDay()]);
        Checkout::factory()->for($this->donor)->completed()->create();

        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned')
            ->assertOk()
            ->assertSee('1 abandoned checkout in the last 30 days; 1 donated later.')
            ->assertSee('Oct 18, 7:30 PM')
            ->assertSee('Jamie Rivera')
            ->assertSee('Monthly')
            ->assertSee('<summary>Oct 18 · Jamie Rivera · $50.00</summary>', false)
            // Reached from Donations, which stays highlighted
            ->assertSee('aria-current="page">Donations</a>', false)
            ->assertSee('<a class="back" href="' . route('admin.donations') . '">← Back to Donations</a>', false)
            // No page of their own, so no View links
            ->assertDontSee('>View</a>', false);
    }

    public function test_the_period_narrows_the_list(): void
    {
        $this->expired(3);
        $this->expired(45);

        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned')
            ->assertSee('1 abandoned checkout in the last 30 days; 0 donated later.');

        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned?period=90')
            ->assertSee('2 abandoned checkouts in the last 90 days; 0 donated later.');
    }

    public function test_says_when_there_are_no_abandoned_checkouts(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned')
            ->assertOk()
            ->assertSee('No abandoned checkouts in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned?period=all')
            ->assertSee('No abandoned checkouts yet.');
    }

    public function test_the_csv_download_has_the_donors_contact_details(): void
    {
        $checkout = $this->expired(3);
        $this->expired(45);

        $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned?period=90')
            ->assertSee(route('admin.donations.abandoned.csv', ['period' => '90']));

        $response = $this->actingAs($this->admin)
            ->get('/admin/donations/abandoned/csv')
            ->assertOk()
            ->assertDownload('abandoned-checkouts-2026-10-21.csv');

        $lines = explode("\n", trim($response->getContent()));

        $this->assertSame('Reference,Started,Name,Email,Type,Amount,Currency,"Donated later"', $lines[0]);
        $this->assertSame("{$checkout->reference},\"Sun, Oct 18, 2026 7:30 PM MDT\",\"Jamie Rivera\",jamie@example.com,Monthly,50.00,USD,No", $lines[1]);
        $this->assertCount(2, $lines);
    }
}
