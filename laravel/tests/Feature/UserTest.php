<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_created_from_an_email_address_is_an_unverified_donor_without_a_password(): void
    {
        $user = User::create(['email' => 'donor@example.com']);

        $user->refresh();

        $this->assertSame(User::ROLE_DONOR, $user->role);
        $this->assertNull($user->password);
        $this->assertNull($user->name);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_addresses_are_stored_lowercase_and_trimmed(): void
    {
        $user = User::create(['email' => '  Donor@Example.COM ']);

        $this->assertSame('donor@example.com', $user->refresh()->email);
    }

    public function test_the_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['email' => 'donor@example.com', 'role' => User::ROLE_ADMIN]);

        $this->assertSame(User::ROLE_DONOR, $user->refresh()->role);
    }

    public function test_passwords_are_hashed(): void
    {
        $user = User::create(['email' => 'donor@example.com', 'password' => 'a long passphrase']);

        $this->assertNotSame('a long passphrase', $user->refresh()->password);
        $this->assertTrue(Hash::check('a long passphrase', $user->password));
    }

    public function test_the_factory_makes_donors_and_its_states_verify_and_set_a_password(): void
    {
        $donor = User::factory()->create();
        $member = User::factory()->verified()->withPassword()->create();

        $this->assertSame(User::ROLE_DONOR, $donor->role);
        $this->assertNull($donor->password);
        $this->assertNull($donor->email_verified_at);
        $this->assertNotNull($member->email_verified_at);
        $this->assertTrue(Hash::check('password', $member->password));
    }

    public function test_a_deleted_user_is_kept_and_its_email_address_stays_reserved(): void
    {
        $user = User::create(['email' => 'spam@example.com']);

        $user->delete();

        $this->assertSoftDeleted($user);
        $this->assertNull(User::firstWhere('email', 'spam@example.com'));

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        User::create(['email' => 'Spam@Example.com']);
    }
}
