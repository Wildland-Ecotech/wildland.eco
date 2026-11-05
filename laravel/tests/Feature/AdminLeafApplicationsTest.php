<?php

namespace Tests\Feature;

use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin leaf application pages: /admin/leaves, its CSV download, and one
 * application in full.
 */
class AdminLeafApplicationsTest extends TestCase
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
     * Save an application made $daysAgo days ago, with any fields overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function application(array $overrides = [], int $daysAgo = 3): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'ready_date' => null,
            'referral_source' => 'A flyer',
            'questions' => "Do you take pine needles?\nAnd acorns?",
            'terms_accepted_at' => now()->subDays($daysAgo),
            ...$overrides,
        ]);

        $application->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $application;
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
        fwrite($handle, $this->actingAs($this->admin)->get(route('admin.leaves.csv', $query))->getContent());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_only_signed_in_admins_can_see_leaf_applications(): void
    {
        $application = $this->application();
        $pages = ['/admin/leaves', '/admin/leaves/csv', "/admin/leaves/{$application->id}"];

        foreach ($pages as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($pages as $page) {
            $this->actingAs($donor)->get($page)->assertForbidden();
        }
    }

    public function test_lists_applications_with_a_link_to_each(): void
    {
        $application = $this->application(['type' => LeafApplication::TYPE_PICKUP, 'ready_now' => false, 'ready_date' => '2026-11-08']);

        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertOk()
            ->assertSee('1 application in the last 30 days.')
            ->assertSee('Oct 18, 7:30 PM')
            ->assertSee('Jamie Rivera')
            ->assertSee('Pickup')
            ->assertSee('Sun, Nov 8, 2026')
            // The ID is the link, rather than a separate View column
            ->assertSee('<a class="record-link" href="' . route('admin.leaves.show', $application->id) . '">' . $application->id . '</a>', false)
            ->assertDontSee('>View</a>', false)
            ->assertSee('aria-current="page">Leaf Applications</a>', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_each_application_has_a_short_line_for_phones(): void
    {
        $this->application();
        $this->application(['name' => 'Sam Lee', 'type' => LeafApplication::TYPE_PICKUP, 'estimated_bag_count' => 1], daysAgo: 365);

        // Date, name, type, and bags, with the year only when it isn't this year
        $this->actingAs($this->admin)
            ->get('/admin/leaves?period=all')
            ->assertSee('<summary>Oct 18 · Jamie Rivera · Drop-off · 12 bags</summary>', false)
            ->assertSee('<summary>Oct 21, 2025 · Sam Lee · Pickup · 1 bag</summary>', false)
            // The table's time also has the year only when it isn't this year
            ->assertSee('Oct 21, 2025, 7:30 PM');
    }

    public function test_types_and_periods_narrow_the_list(): void
    {
        $this->application(['name' => 'Drops Off']);
        $this->application(['name' => 'Picks Up', 'type' => LeafApplication::TYPE_PICKUP]);
        $this->application(['name' => 'Last Spring', 'type' => LeafApplication::TYPE_PICKUP], daysAgo: 200);

        $this->actingAs($this->admin)
            ->get('/admin/leaves?type[]=drop_off')
            ->assertSee('Drops Off')
            ->assertDontSee('Picks Up')
            ->assertSee('1 drop-off application in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/leaves?type[]=pickup&period=365')
            ->assertSee('Picks Up')
            ->assertSee('Last Spring')
            ->assertDontSee('Drops Off')
            ->assertSee('2 pickup applications in the last 365 days.');

        $this->actingAs($this->admin)
            ->get('/admin/leaves?type[]=pickup&type[]=drop_off&period=all')
            ->assertSee('3 applications to date.');
    }

    public function test_unexpected_filters_fall_back_to_the_defaults(): void
    {
        $this->application();

        $this->actingAs($this->admin)
            ->get('/admin/leaves?period=7&type[]=mail&type=pickup')
            ->assertOk()
            ->assertSee('1 application in the last 30 days.');
    }

    public function test_says_when_there_are_no_applications(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertOk()
            ->assertSee('No applications in the last 30 days.');

        $this->actingAs($this->admin)
            ->get('/admin/leaves?period=all')
            ->assertSee('No applications yet.');
    }

    public function test_the_csv_download_has_every_detail_for_the_same_filters(): void
    {
        $this->application(['name' => '=HYPERLINK("https://example.com")']);
        $this->application(['name' => 'Picks Up', 'type' => LeafApplication::TYPE_PICKUP]);

        $this->actingAs($this->admin)
            ->get(route('admin.leaves.csv', ['type' => ['drop_off']]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('leaf-applications-2026-10-21.csv');

        $rows = $this->csvRows(['type' => ['drop_off']]);

        $this->assertSame(['ID', 'Applied', 'Type', 'Name', 'Email', 'Phone', 'Address', 'Estimated bags', 'Bags ready', 'Heard about us', 'Questions'], $rows[0]);
        $this->assertCount(2, $rows);
        // Kept from running as a formula in a spreadsheet
        $this->assertSame('\'=HYPERLINK("https://example.com")', $rows[1][3]);
        $this->assertSame('(970) 555-0123', $rows[1][5]);
    }

    public function test_shows_one_application_in_full(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertOk()
            ->assertSee("Application {$application->id}")
            ->assertSee('Sun, Oct 18, 2026 7:30 PM MDT')
            ->assertSee('jamie@example.com')
            ->assertSee('(970) 555-0123')
            ->assertSee('123 Example St, Fort Collins, CO 80521')
            ->assertSee('Now')
            ->assertSee('A flyer')
            ->assertSee("Do you take pine needles?\nAnd acorns?")
            ->assertSee('Terms accepted')
            ->assertDontSee('was deleted on');
    }

    public function test_a_deleted_application_is_shown_and_marked_as_deleted(): void
    {
        $application = $this->application();
        $application->delete();

        $this->actingAs($this->admin)
            ->get('/admin/leaves')
            ->assertDontSee('Jamie Rivera');

        $this->actingAs($this->admin)
            ->get("/admin/leaves/{$application->id}")
            ->assertOk()
            ->assertSee('This application was deleted on Wed, Oct 21, 2026 7:30 PM MDT');
    }

    public function test_an_unknown_application_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/leaves/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/leaves/99999999999999999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/leaves/abc')->assertNotFound();
    }
}
