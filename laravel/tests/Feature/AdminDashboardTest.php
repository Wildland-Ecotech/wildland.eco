<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin dashboard, /admin: who is signed in, the last 90 days at a
 * glance (Recent Activity), and the donation totals (Donation Statistics).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
    }

    /**
     * A received donation paid at the given Fort Collins time. Times are
     * stored in UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function paid(string $localTime, int $amountCents, ?int $feeCents, array $attributes = []): Donation
    {
        return Donation::factory()->create([
            'paid_at' => Carbon::parse($localTime, 'America/Denver')->utc(),
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            ...$attributes,
        ]);
    }

    /**
     * A leaf application made $daysAgo days ago.
     */
    private function application(int $daysAgo): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'terms_accepted_at' => now(),
        ]);

        $application->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $application;
    }

    /**
     * A donation form submission with this outcome, made $daysAgo days ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submission(string $reference, string $outcome, int $daysAgo = 1, array $attributes = []): FormSubmission
    {
        $submission = FormSubmission::create([
            'reference' => $reference,
            'form' => FormSubmission::FORM_DONATION,
            'outcome' => $outcome,
            ...$attributes,
        ]);

        $submission->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $submission;
    }

    public function test_counts_the_last_90_days_like_the_lists(): void
    {
        // 2 donations received; one older, and a failed payment, aren't counted
        Donation::factory()->count(2)->create(['paid_at' => now()->subDays(10)]);
        Donation::factory()->create(['paid_at' => now()->subDays(100)]);
        Donation::factory()->failed()->create();

        // 1 leaf application in the last 90 days
        $this->application(10);
        $this->application(120);

        // 3 unresolved errors; a submission that succeeded isn't one, nor is
        // an error the visitor got through later, or one an admin closed
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('EXP333', FormSubmission::OUTCOME_EXPIRED);
        $this->submission('ERR444', FormSubmission::OUTCOME_ERROR, daysAgo: 80);
        $this->submission('SAV555', FormSubmission::OUTCOME_SAVED);
        $this->submission('REJ666', FormSubmission::OUTCOME_REJECTED, daysAgo: 3, attributes: ['email' => 'jamie@example.com']);
        $this->submission('PAID77', FormSubmission::OUTCOME_SENT_TO_PAYMENT, daysAgo: 2, attributes: [
            'email' => 'jamie@example.com',
            'checkout_id' => Checkout::factory()->completed()->create()->id,
        ]);
        $this->submission('BOT888', FormSubmission::OUTCOME_REJECTED)->close($this->admin, FormSubmission::RESOLUTION_SPAM);
        $this->submission('TST999', FormSubmission::OUTCOME_REJECTED)->close($this->admin, FormSubmission::RESOLUTION_TEST);
        $this->submission('FIX000', FormSubmission::OUTCOME_REJECTED)->close($this->admin, FormSubmission::RESOLUTION_RESOLVED);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Signed in as')
            ->assertSeeInOrder(['Recent Activity', 'The last 90 days.'])
            ->assertSeeInOrder([
                '<span class="count">2</span>', 'Donations received',
                '<span class="count">1</span>', 'Leaf applications',
                '<span class="count">3</span>', 'Unresolved errors',
                '<span class="count">0</span>', 'Applications to review',
            ], false);
    }

    public function test_counts_applications_from_the_last_90_days_that_need_review(): void
    {
        $this->application(10)->forceFill(['contact_review' => [LeafApplication::REVIEW_SHARED_PHONE]])->save();
        $this->application(20)->forceFill(['contact_review' => [LeafApplication::REVIEW_CONFLICT]])->save();
        // Reviewed already, or older than the overview
        $this->application(5)->forceFill(['contact_review' => [LeafApplication::REVIEW_ADDRESS], 'contact_reviewed_at' => now()])->save();
        $this->application(120)->forceFill(['contact_review' => [LeafApplication::REVIEW_SHARED_EMAIL]])->save();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSeeInOrder(['<span class="count">2</span>', 'Applications to review'], false);
    }

    public function test_each_count_links_to_its_list_for_the_same_period(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin')->assertOk();

        foreach (['admin.donations', 'admin.leaves'] as $route) {
            $response->assertSee('href="' . route($route, ['period' => '90']) . '"', false);
        }

        $response->assertSee('href="' . route('admin.contacts.review') . '"', false);

        // Unresolved errors link to the list without the ones resolved
        $response->assertSee('href="' . e(route('admin.errors', ['period' => '90', 'later' => '0', 'resolved' => '0'])) . '"', false);

        $response->assertSee('<span class="count">0</span>', false);
    }

    public function test_shows_each_grouping_of_totals_like_the_command(): void
    {
        $this->paid('2026-01-15 10:00', 5000, 175);
        $this->paid('2026-02-10 10:00', 2000, 88);
        $this->paid('2026-02-28 10:00', 5000, 175, ['status' => Donation::STATUS_REFUNDED, 'refunded_cents' => 5000]);
        Donation::factory()->failed()->create();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['Recent Activity', 'Donation Statistics', 'Month over month', 'By quarter', 'By year', 'All time'])
            ->assertSee('Jan 2026')
            ->assertSee('Q1 2026')
            // $120.00 donated, $50.00 refunded, and $4.38 in fees
            ->assertSee('$120.00')
            ->assertSee('$50.00')
            ->assertSee('$4.38')
            ->assertSee('$65.62')
            ->assertSee('href="' . route('admin.donations.totals.csv') . '"', false)
            ->assertDontSee("Stripe's fee isn't recorded yet");
    }

    public function test_each_total_has_a_short_line_for_phones(): void
    {
        $this->paid('2026-01-15 10:00', 5000, 175);
        $this->paid('2026-01-20 10:00', 2000, 88);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSee('<summary>Jan 2026 · 2 donations · $70.00</summary>', false)
            ->assertSee('<summary>All time · 2 donations · $70.00</summary>', false);
    }

    public function test_donations_without_a_fee_yet_are_pointed_out(): void
    {
        $this->paid('2026-01-15 10:00', 5000, null);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSee("Stripe's fee isn't recorded yet for 1 donation, so the fees leave it out");
    }

    public function test_says_when_there_are_no_donations(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['Donation Statistics', 'No donations received yet.'])
            ->assertDontSee('Month over month')
            ->assertDontSee(route('admin.donations.totals.csv'));
    }
}
