<?php

namespace Tests\Feature;

use App\Http\Controllers\LoginController;
use App\Models\User;
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
     * An admin whose password is "password", from the factory.
     */
    private function admin(): User
    {
        return User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera', 'email' => 'admin@example.com']);
    }

    public function test_the_sign_in_page_is_a_plain_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('action="' . route('login.store') . '"', false)
            ->assertSee('name="email"', false)
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_an_admin_signs_in_and_sees_the_admin_page(): void
    {
        $admin = $this->admin();

        $this->from('/login')
            ->post('/login', ['email' => ' Admin@Example.com ', 'password' => 'password'])
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
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect(route('admin'));
    }

    public function test_a_wrong_password_is_refused_keeping_only_the_email(): void
    {
        $this->admin();

        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE])
            ->assertSessionHasInput('email', 'admin@example.com')
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_an_unknown_email_address_gets_the_same_message(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_donors_cannot_sign_in_even_with_a_password(): void
    {
        User::factory()->withPassword()->create(['email' => 'donor@example.com']);

        $this->from('/login')
            ->post('/login', ['email' => 'donor@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_deleted_admin_cannot_sign_in(): void
    {
        $this->admin()->delete();

        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['form' => LoginController::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_missing_fields_get_their_own_messages(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors([
                'email' => 'Enter your email address.',
                'password' => 'Enter your password.',
            ]);

        $this->from('/login')
            ->post('/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, such as name@example.com.']);
    }

    public function test_failed_attempts_are_limited_per_email_address(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt < LoginController::MAX_ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->from('/login')->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong password']);
        }

        // Even the right password waits until the limit resets
        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS_MESSAGE]);

        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
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
}
