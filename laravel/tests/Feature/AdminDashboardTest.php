<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin dashboard, /admin: who is signed in, and the last 90 days at a
 * glance.
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
     */
    private function submission(string $reference, string $outcome, int $daysAgo = 1): FormSubmission
    {
        $submission = FormSubmission::create([
            'reference' => $reference,
            'form' => FormSubmission::FORM_DONATION,
            'outcome' => $outcome,
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

        // 3 errors; a submission that succeeded isn't one
        $this->submission('REJ222', FormSubmission::OUTCOME_REJECTED);
        $this->submission('EXP333', FormSubmission::OUTCOME_EXPIRED);
        $this->submission('ERR444', FormSubmission::OUTCOME_ERROR, daysAgo: 80);
        $this->submission('SAV555', FormSubmission::OUTCOME_SAVED);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Signed in as')
            ->assertSee('Last 90 Days')
            ->assertSeeInOrder([
                '<span class="count">2</span>', 'Donations received',
                '<span class="count">1</span>', 'Leaf applications',
                '<span class="count">3</span>', 'Errors',
            ], false);
    }

    public function test_each_count_links_to_its_list_for_the_same_period(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin')->assertOk();

        foreach (['admin.donations', 'admin.leaves', 'admin.errors'] as $route) {
            $response->assertSee(route($route, ['period' => '90']));
        }

        $response->assertSee('<span class="count">0</span>', false);
    }
}
