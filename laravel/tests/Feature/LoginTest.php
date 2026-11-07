<?php

namespace Tests\Feature;

use App\Http\Controllers\LoginController;
use App\Http\Middleware\RecordLoginAttempt;
use App\Http\Requests\LoginRequest;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\ProofOfWork;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();
    }

    /**
     * The sign-in form's fields with a solved proof of work, as
     * resources/js/proof-of-work.js would send them.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function form(array $fields): array
    {
        $challenge = app(ProofOfWork::class)->issue();
        $prefix = explode('.', $challenge)[2];

        for ($nonce = 0; ! str_starts_with(hash('sha256', "{$challenge}:{$nonce}"), $prefix); $nonce++);

        return ['pow_challenge' => $challenge, 'pow_nonce' => (string) $nonce, ...$fields];
    }

    /**
     * An admin whose password is "password", from the factory.
     */
    private function admin(): User
    {
        return User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera', 'email' => 'admin@example.com']);
    }

    public function test_the_sign_in_page_is_a_plain_form_with_a_proof_of_work(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('action="' . route('login.store') . '"', false)
            ->assertSee('data-proof-of-work data-form-tokens-url="' . route('form-tokens') . '"', false)
            ->assertSee('name="pow_challenge" value="', false)
            ->assertSee('<button type="submit" class="button full small" data-proof-of-work-button>Sign In</button>', false)
            ->assertSee('To protect against password guessing, signing in needs JavaScript.')
            ->assertSee('name="email"', false)
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_an_admin_signs_in_and_sees_the_admin_page(): void
    {
        $admin = $this->admin();

        $this->from('/login')
            ->post('/login', $this->form(['email' => ' Admin@Example.com ', 'password' => 'password']))
            ->assertRedirect(route('admin'));

        $this->assertAuthenticatedAs($admin);

        $response = $this->get('/admin')
            ->assertOk()
            ->assertSee('Signed in as')
            ->assertSee('Sam Rivera')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_guests_are_sent_to_sign_in_and_back_to_the_page_they_asked_for(): void
    {
        $this->admin();

        $this->get('/admin')->assertRedirect(route('login'));

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertRedirect(route('admin'));
    }

    public function test_a_wrong_password_is_refused_keeping_only_the_email(): void
    {
        $this->admin();

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'wrong password']))
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE])
            ->assertSessionHasInput('email', 'admin@example.com')
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_an_unknown_email_address_gets_the_same_message(): void
    {
        $this->from('/login')
            ->post('/login', $this->form(['email' => 'nobody@example.com', 'password' => 'password']))
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_donors_cannot_sign_in_even_with_a_password(): void
    {
        User::factory()->withPassword()->create(['email' => 'donor@example.com']);

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'donor@example.com', 'password' => 'password']))
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_deleted_admin_cannot_sign_in(): void
    {
        $this->admin()->delete();

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_missing_fields_get_their_own_messages(): void
    {
        $this->from('/login')
            ->post('/login', $this->form(['email' => '', 'password' => '']))
            ->assertSessionHasErrors([
                'email' => 'Enter your email address.',
                'password' => 'Enter your password.',
            ]);

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'not-an-email', 'password' => 'password']))
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, such as name@example.com.']);
    }

    public function test_failed_attempts_are_limited_per_email_address(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt < LoginController::MAX_ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->from('/login')->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'wrong password']));
        }

        // Even the right password waits until the limit resets
        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS_MESSAGE]);

        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertRedirect(route('admin'));
    }

    public function test_signed_in_admins_skip_the_sign_in_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/login')
            ->assertRedirect(route('admin'));
    }

    public function test_signed_in_users_who_arent_admins_are_refused(): void
    {
        $this->actingAs(User::factory()->withPassword()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_removing_an_admin_takes_effect_right_away(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $admin->role = User::ROLE_DONOR;
        $admin->save();

        $this->get('/admin')->assertForbidden();
    }

    public function test_a_new_password_signs_out_other_sessions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $admin->password = 'a new long passphrase';
        $admin->save();

        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs($this->admin())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->get('/login')->assertSee('You have signed out.');
    }

    public function test_signing_in_needs_the_proof_of_work(): void
    {
        $this->admin();

        // Without one, even the right password is refused
        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['pow_nonce' => LoginRequest::PROOF_OF_WORK_MESSAGE]);
        $this->assertGuest();

        // A challenge is used up once checked
        $fields = $this->form(['email' => 'admin@example.com', 'password' => 'wrong password']);
        $this->from('/login')->post('/login', $fields);
        $this->from('/login')
            ->post('/login', [...$fields, 'password' => 'password'])
            ->assertSessionHasErrors(['pow_nonce' => LoginRequest::PROOF_OF_WORK_MESSAGE]);
        $this->assertGuest();

        // The message shows above the fields
        $this->from('/login')
            ->followingRedirects()
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSee(LoginRequest::PROOF_OF_WORK_MESSAGE);
    }

    public function test_failed_attempts_are_limited_per_ip_address_across_email_addresses(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt < LoginController::MAX_ATTEMPTS_PER_IP_PER_HOUR; $attempt++) {
            $this->from('/login')->post('/login', $this->form(['email' => "someone{$attempt}@example.com", 'password' => 'guess']));
        }

        // A different address from the same IP address, even with the right password
        $this->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS_FROM_IP_MESSAGE]);
        $this->assertGuest();

        // Another IP address isn't held back
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->from('/login')
            ->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']))
            ->assertRedirect(route('admin'));
    }

    public function test_every_attempt_is_recorded_without_the_password(): void
    {
        $admin = $this->admin();
        User::factory()->withPassword()->create(['email' => 'donor@example.com']);
        $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'];

        $this->withServerVariables($browser)->from('/login')->post('/login', $this->form(['email' => ' Admin@Example.com ', 'password' => 'secret guess']));
        $this->from('/login')->post('/login', $this->form(['email' => 'nobody@example.com', 'password' => 'secret guess']));
        $this->from('/login')->post('/login', $this->form(['email' => 'donor@example.com', 'password' => 'password']));
        $this->from('/login')->post('/login', ['email' => 'admin@example.com', 'password' => 'secret guess']);
        $this->from('/login')->post('/login', $this->form(['email' => '', 'password' => '']));
        $this->withServerVariables($browser)->from('/login')->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'password']));

        $attempts = LoginAttempt::orderBy('id')->get();

        $this->assertSame(
            [
                ['admin@example.com', $admin->id, LoginAttempt::OUTCOME_FAILED, 'Wrong password'],
                ['nobody@example.com', null, LoginAttempt::OUTCOME_FAILED, 'No admin account'],
                // A donor has no admin account
                ['donor@example.com', null, LoginAttempt::OUTCOME_FAILED, 'No admin account'],
                ['admin@example.com', $admin->id, LoginAttempt::OUTCOME_UNVERIFIED, 'No proof of work'],
                [null, null, LoginAttempt::OUTCOME_INCOMPLETE, 'Missing email or password'],
                ['admin@example.com', $admin->id, LoginAttempt::OUTCOME_SIGNED_IN, 'Signed in'],
            ],
            $attempts->map(fn (LoginAttempt $attempt) => [$attempt->email, $attempt->user_id, $attempt->outcome, $attempt->label()])->all(),
        );

        $this->assertSame('127.0.0.1', $attempts->last()->ip_address);
        $this->assertSame($browser['HTTP_USER_AGENT'], $attempts->last()->user_agent);

        // No password, typed or otherwise, anywhere in what was stored
        foreach ($attempts as $attempt) {
            $this->assertStringNotContainsString('secret guess', json_encode($attempt->getAttributes()));
            $this->assertArrayNotHasKey('password', $attempt->getAttributes());
        }
    }

    public function test_too_many_attempts_are_recorded(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt <= LoginController::MAX_ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->from('/login')->post('/login', $this->form(['email' => 'admin@example.com', 'password' => 'wrong password']));
        }

        $this->assertSame(LoginAttempt::OUTCOME_RATE_LIMITED, LoginAttempt::latest('id')->first()->outcome);
        $this->assertSame(LoginController::MAX_ATTEMPTS_PER_MINUTE, LoginAttempt::where('outcome', LoginAttempt::OUTCOME_FAILED)->count());
    }

    public function test_an_attempt_from_an_expired_page_is_recorded(): void
    {
        // Runs where the sign-in route does: after the session starts, before the CSRF check
        Route::middleware(['web', RecordLoginAttempt::class])->post('/test/expired-login', fn () => throw new TokenMismatchException);

        $this->post('/test/expired-login', ['email' => 'admin@example.com', 'password' => 'password'])->assertStatus(419);

        $attempt = LoginAttempt::sole();
        $this->assertSame(LoginAttempt::OUTCOME_EXPIRED, $attempt->outcome);
        $this->assertSame('Page expired', $attempt->label());
    }
}
