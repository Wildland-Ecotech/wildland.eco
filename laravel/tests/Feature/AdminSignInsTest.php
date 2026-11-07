<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Reports\SignInReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Sign-Ins page (/admin/sign-ins), the Sign-Ins button on Error
 * Logs, and the warning on the dashboard and Error Logs while attempts to
 * sign in failed in the last day.
 */
class AdminSignInsTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-07 14:41', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['email' => 'sam@example.com']);
    }

    /**
     * An attempt to sign in, made $minutesAgo minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function attempt(string $outcome, string $email = 'sam@example.com', string $ip = '198.51.100.7', int $minutesAgo = 60, array $attributes = []): LoginAttempt
    {
        $attempt = LoginAttempt::forceCreate([
            'email' => $email,
            'user_id' => $email === 'sam@example.com' ? $this->admin->id : null,
            'ip_address' => $ip,
            'user_agent' => self::FIREFOX,
            'outcome' => $outcome,
            ...$attributes,
        ]);

        $attempt->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $attempt;
    }

    public function test_only_admins_see_sign_ins(): void
    {
        $this->get(route('admin.sign-ins'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->withPassword()->create())
            ->get(route('admin.sign-ins'))
            ->assertForbidden();
    }

    public function test_lists_every_attempt_newest_first(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN, minutesAgo: 120);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, minutesAgo: 30);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, 'guess@example.com', '203.0.113.77', 10, ['user_agent' => 'curl/8.5.0']);

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertOk()
            // Opened from Error Logs, which the menu shows as current
            ->assertSee('<a href="' . route('admin.errors') . '" aria-current="page">', false)
            ->assertSee('3 sign-in attempts, newest first.')
            ->assertSeeInOrder(['Nov 7, 2:31 PM', 'guess@example.com', 'No admin account', '203.0.113.77', 'curl/8.5.0'])
            ->assertSeeInOrder(['Nov 7, 2:31 PM', 'Nov 7, 2:11 PM', 'Nov 7, 12:41 PM'])
            ->assertSeeInOrder(['Wrong password', 'Signed in'])
            ->assertSee('Firefox on Linux')
            ->assertSee('Passwords are never recorded')
            ->assertSee('← Back to Error Logs');
    }

    public function test_each_attempt_shows_success_or_failure_beside_its_words(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN);
        $this->attempt(LoginAttempt::OUTCOME_RATE_LIMITED, minutesAgo: 5);

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertSee('<span class="status-icon success" aria-hidden="true">✓</span>Signed in', false)
            ->assertSee('<span class="status-icon failure" aria-hidden="true">✕</span>Too many attempts', false)
            // The phone line, with the outcome read out in place of the icon
            ->assertSee('<span class="visually-hidden">Failed: </span>Nov 7, 2:36 PM · sam@example.com · Too many attempts', false);
    }

    public function test_shows_only_failed_attempts(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN, minutesAgo: 120);
        $this->attempt(LoginAttempt::OUTCOME_UNVERIFIED, 'bot@example.com');

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins', ['show' => 'failed']))
            ->assertSee('1 failed sign-in attempt, newest first.')
            ->assertSee('No proof of work')
            ->assertDontSee('Signed in</span>', false)
            ->assertDontSee('Nov 7, 12:41 PM');
    }

    public function test_searches_by_email_or_ip_address(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN, minutesAgo: 120);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, 'guess@example.com', '203.0.113.77', 10);

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins', ['q' => ' GUESS ']))
            ->assertSee('1 sign-in attempt matching “GUESS”, newest first.')
            ->assertSee('guess@example.com')
            ->assertDontSee('Nov 7, 12:41 PM');

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins', ['q' => '203.0.113']))
            ->assertSee('1 sign-in attempt matching “203.0.113”, newest first.')
            ->assertSee('guess@example.com');

        // A percent sign is only a percent sign
        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins', ['q' => '%']))
            ->assertSee('No sign-in attempts matching “%”.');
    }

    public function test_each_ip_address_links_to_its_attempts(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '203.0.113.77');

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertSee('<a href="' . e(route('admin.sign-ins', ['q' => '203.0.113.77'])) . '">203.0.113.77</a>', false);

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins', ['show' => 'failed']))
            ->assertSee('<a href="' . e(route('admin.sign-ins', ['show' => 'failed', 'q' => '203.0.113.77'])) . '">203.0.113.77</a>', false);
    }

    public function test_says_when_there_are_none(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertSee('No sign-in attempts yet.')
            ->assertDontSee('records-table', false)
            ->assertDontSee('notice warning', false);
    }

    public function test_lists_only_the_newest_when_there_are_many(): void
    {
        $limit = SignInReport::LIST_LIMIT;

        LoginAttempt::insert(array_map(fn (int $minutes) => [
            'email' => 'sam@example.com',
            'ip_address' => '198.51.100.7',
            'outcome' => LoginAttempt::OUTCOME_SIGNED_IN,
            'created_at' => now()->subMinutes($minutes),
            'updated_at' => now()->subMinutes($minutes),
        ], range(1, $limit + 1)));

        $response = $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertSee('The newest ' . number_format($limit) . ' of ' . number_format($limit + 1) . ' sign-in attempts.')
            ->assertSee('Nov 7, 2:40 PM');

        $this->assertSame($limit, substr_count($response->getContent(), '<span class="status-icon success" aria-hidden="true">✓</span>Signed in'));
    }

    public function test_names_browsers_from_their_user_agents(): void
    {
        $browser = fn (?string $agent) => (new LoginAttempt)->forceFill(['user_agent' => $agent])->browser();

        $this->assertSame('Firefox on Linux', $browser(self::FIREFOX));
        $this->assertSame('Safari on iPhone', $browser(self::IPHONE));
        $this->assertSame('Chrome on Windows', $browser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'));
        $this->assertSame('Edge on Windows', $browser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'));
        $this->assertSame('Safari on Mac', $browser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15'));
        $this->assertSame('Chrome on Android', $browser('Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'));
        // Scripts and headless browsers are shown as they are
        $this->assertSame('python-requests/2.32.3', $browser('python-requests/2.32.3'));
        $this->assertStringStartsWith('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36', $browser('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36'));
        $this->assertSame('', $browser(null));
    }

    public function test_error_logs_has_a_sign_ins_button(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.errors'))
            ->assertSee('<a class="button dark small" href="' . route('admin.sign-ins') . '">Sign-Ins</a>', false)
            ->assertDontSee('Review Sign-Ins');
    }

    public function test_warns_about_failed_attempts_in_the_last_day(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_FAILED, 'guess@example.com', '203.0.113.77', 10);
        $this->attempt(LoginAttempt::OUTCOME_RATE_LIMITED, 'guess@example.com', '203.0.113.77', 5);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '198.51.100.7', minutesAgo: 20);
        // Neither a success nor a failure from before yesterday counts
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN, minutesAgo: 1);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '192.0.2.1', minutesAgo: 25 * 60);

        $warning = '3 failed sign-in attempts in the last day, 2 from 203.0.113.77.';
        $review = '<a class="button dark small" href="' . e(route('admin.sign-ins', ['show' => 'failed'])) . '">Review Sign-Ins</a>';

        foreach (['admin', 'admin.errors'] as $page) {
            $this->actingAs($this->admin)
                ->get(route($page))
                ->assertSeeInOrder(['<div class="notice warning" role="status">', $warning, $review], false);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.sign-ins'))
            ->assertSeeInOrder(['<div class="notice warning" role="status">', $warning], false);
    }

    public function test_the_warning_says_where_the_attempts_came_from(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '203.0.113.77', minutesAgo: 10);

        $this->actingAs($this->admin)
            ->get(route('admin'))
            ->assertSee('1 failed sign-in attempt in the last day, from 203.0.113.77.');

        $this->attempt(LoginAttempt::OUTCOME_UNVERIFIED, ip: '203.0.113.77', minutesAgo: 5);

        $this->actingAs($this->admin)
            ->get(route('admin'))
            ->assertSee('2 failed sign-in attempts in the last day, all from 203.0.113.77.');

        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '198.51.100.7', minutesAgo: 5);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '198.51.100.7', minutesAgo: 4);

        // A tie goes to the address that tried last
        $this->actingAs($this->admin)
            ->get(route('admin'))
            ->assertSee('4 failed sign-in attempts in the last day, 2 from 198.51.100.7.');
    }

    public function test_the_warning_says_when_each_came_from_a_different_address(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '203.0.113.77', minutesAgo: 10);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, ip: '198.51.100.7', minutesAgo: 5);

        $this->actingAs($this->admin)
            ->get(route('admin'))
            ->assertSee('2 failed sign-in attempts in the last day, each from a different IP address.');
    }

    public function test_no_warning_without_failures_in_the_last_day(): void
    {
        $this->attempt(LoginAttempt::OUTCOME_SIGNED_IN, minutesAgo: 1);
        $this->attempt(LoginAttempt::OUTCOME_FAILED, minutesAgo: 25 * 60);

        foreach (['admin', 'admin.errors'] as $page) {
            $this->actingAs($this->admin)
                ->get(route($page))
                ->assertDontSee('Review Sign-Ins');
        }
    }
}
