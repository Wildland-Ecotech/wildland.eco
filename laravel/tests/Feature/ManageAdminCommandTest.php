<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManageAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a long passphrase';

    public function test_a_new_admin_account_is_created_with_a_hashed_password(): void
    {
        $this->artisan('users:admin', ['email' => ' Board@Example.com '])
            ->expectsConfirmation('Create an admin account for board@example.com?', 'yes')
            ->expectsQuestion('Name', 'Sam Rivera')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('Created an admin account for board@example.com.')
            ->assertSuccessful();

        $admin = User::sole();

        $this->assertSame('board@example.com', $admin->email);
        $this->assertSame('Sam Rivera', $admin->name);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertNotSame(self::PASSWORD, $admin->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_an_existing_donor_keeps_their_account_and_name(): void
    {
        $donor = User::factory()->create(['name' => 'Jamie Lee', 'email' => 'jamie@example.com']);

        // Each line of output only counts toward the first expectation it
        // matches, so no expected text here may appear in another line.
        // Twelve characters is the shortest password allowed.
        $this->artisan('users:admin', ['email' => 'jamie@example.com'])
            ->expectsOutputToContain('Jamie Lee')
            ->expectsOutputToContain('Donor since')
            ->expectsConfirmation('Make jamie@example.com an admin?', 'yes')
            ->expectsQuestion('Password', 'twelve chars')
            ->expectsQuestion('Confirm password', 'twelve chars')
            ->expectsOutputToContain('jamie@example.com is now an admin.')
            ->assertSuccessful();

        $admin = User::sole();

        $this->assertSame($donor->id, $admin->id);
        $this->assertSame('Jamie Lee', $admin->name);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue(Hash::check('twelve chars', $admin->password));
    }

    public function test_a_donor_without_a_name_is_asked_for_one(): void
    {
        User::factory()->create(['name' => null, 'email' => 'jamie@example.com']);

        $this->artisan('users:admin', ['email' => 'jamie@example.com'])
            ->expectsConfirmation('Make jamie@example.com an admin?', 'yes')
            ->expectsQuestion('Name', 'Jamie Lee')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $this->assertSame('Jamie Lee', User::sole()->name);
    }

    public function test_running_it_again_for_an_admin_sets_a_new_password(): void
    {
        User::factory()->admin()->withPassword()->create(['email' => 'admin@example.com']);

        $this->artisan('users:admin', ['email' => 'admin@example.com'])
            ->expectsConfirmation('Set a new password for admin@example.com?', 'yes')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('admin@example.com has a new password.')
            ->assertSuccessful();

        $admin = User::sole();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertFalse(Hash::check('password', $admin->password));
    }

    public function test_a_password_shorter_than_twelve_characters_is_refused(): void
    {
        User::factory()->create(['email' => 'jamie@example.com']);

        $this->artisan('users:admin', ['email' => 'jamie@example.com'])
            ->expectsConfirmation('Make jamie@example.com an admin?', 'yes')
            ->expectsQuestion('Password', 'elevenchars')
            ->expectsOutputToContain('Use at least 12 characters.')
            ->assertFailed();

        $donor = User::sole();

        $this->assertSame(User::ROLE_DONOR, $donor->role);
        $this->assertNull($donor->password);
    }

    public function test_passwords_that_dont_match_are_refused(): void
    {
        $this->artisan('users:admin', ['email' => 'board@example.com'])
            ->expectsConfirmation('Create an admin account for board@example.com?', 'yes')
            ->expectsQuestion('Name', 'Sam Rivera')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'a different passphrase')
            ->expectsOutputToContain('The passwords don\'t match.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_nothing_changes_without_confirmation(): void
    {
        User::factory()->create(['email' => 'jamie@example.com']);

        $this->artisan('users:admin', ['email' => 'jamie@example.com'])
            ->expectsConfirmation('Make jamie@example.com an admin?', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertSame(User::ROLE_DONOR, User::sole()->role);
    }

    public function test_only_one_valid_email_address_is_accepted(): void
    {
        foreach (['', 'not-an-email', 'a@example.com,b@example.com'] as $email) {
            $this->artisan('users:admin', ['email' => $email])
                ->expectsOutputToContain('Give one email address')
                ->assertExitCode(Command::INVALID);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_deleted_account_cannot_be_made_an_admin(): void
    {
        User::factory()->create(['email' => 'jamie@example.com'])->delete();

        $this->artisan('users:admin', ['email' => 'jamie@example.com'])
            ->expectsOutputToContain('jamie@example.com belongs to a deleted account')
            ->assertFailed();

        $this->assertSame(User::ROLE_DONOR, User::withTrashed()->sole()->role);
    }

    public function test_remove_makes_an_admin_a_donor_without_a_password(): void
    {
        User::factory()->admin()->withPassword()->create(['email' => 'admin@example.com']);

        $this->artisan('users:admin', ['email' => 'admin@example.com', '--remove' => true])
            ->expectsConfirmation('Make admin@example.com a donor again? Their password will be removed.', 'yes')
            ->expectsOutputToContain('admin@example.com is a donor again')
            ->assertSuccessful();

        $user = User::sole();

        $this->assertSame(User::ROLE_DONOR, $user->role);
        $this->assertNull($user->password);
    }

    public function test_remove_refuses_anyone_who_is_not_an_admin(): void
    {
        User::factory()->withPassword()->create(['email' => 'jamie@example.com']);

        $this->artisan('users:admin', ['email' => 'jamie@example.com', '--remove' => true])
            ->expectsOutputToContain('jamie@example.com isn\'t an admin.')
            ->assertFailed();

        $this->artisan('users:admin', ['email' => 'nobody@example.com', '--remove' => true])
            ->expectsOutputToContain('nobody@example.com isn\'t an admin.')
            ->assertFailed();

        $this->assertNotNull(User::sole()->password);
    }
}
