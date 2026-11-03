<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Makes a user an admin and sets their password, or makes an admin a donor
 * again. Admins are only created here: there is no public registration, and
 * the role can't be mass assigned. A board member who has already donated
 * keeps their existing account, so each person has one record. Running it
 * again for an admin sets a new password.
 */
class ManageAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'users:admin
        {email : The admin\'s email address}
        {--remove : Make the admin a donor again, without a password}';

    /**
     * @var string
     */
    protected $description = 'Make a user an admin and set their password, or make an admin a donor again';

    /**
     * The shortest password an admin may have. Length is the only rule, with
     * no character-class requirements.
     */
    public const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error('Give one email address, such as name@example.com.');

            return self::INVALID;
        }

        // Deleted accounts too, since their email addresses stay reserved
        $user = User::withTrashed()->where('email', $email)->first();

        return $this->option('remove') ? $this->remove($email, $user) : $this->makeAdmin($email, $user);
    }

    /**
     * Make the user with the email address an admin, creating them if needed,
     * and set their password.
     */
    private function makeAdmin(string $email, ?User $user): int
    {
        if ($user?->trashed()) {
            $this->components->error("{$email} belongs to a deleted account, so it can't be made an admin.");

            return self::FAILURE;
        }

        $wasAdmin = $user?->role === User::ROLE_ADMIN;

        $this->components->twoColumnDetail('Email', $email);
        $this->components->twoColumnDetail('Name', $user?->name ?: '-');
        $this->components->twoColumnDetail('Account', match (true) {
            $user === null => 'New',
            $wasAdmin => 'Admin',
            default => 'Donor since ' . $user->created_at->copy()->timezone(config('organization.timezone'))->format('M j, Y'),
        });

        $question = match (true) {
            $user === null => "Create an admin account for {$email}?",
            $wasAdmin => "Set a new password for {$email}?",
            default => "Make {$email} an admin?",
        };

        if (! $this->confirm($question)) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $isNew = $user === null;
        $user ??= new User(['email' => $email]);

        if (! $user->name) {
            $user->name = trim(text(
                label: 'Name',
                required: 'Enter the admin\'s name.',
                validate: fn (string $value) => match (true) {
                    trim($value) === '' => 'Enter the admin\'s name.',
                    mb_strlen(trim($value)) > 255 => 'Use at most 255 characters.',
                    default => null,
                },
            ));
        }

        $password = password(
            label: 'Password',
            required: 'Enter a password.',
            validate: fn (string $value) => mb_strlen($value) < self::MIN_PASSWORD_LENGTH
                ? 'Use at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
                : null,
            hint: 'At least ' . self::MIN_PASSWORD_LENGTH . ' characters',
        );

        password(
            label: 'Confirm password',
            required: 'Type the password again.',
            validate: fn (string $value) => $value !== $password
                ? 'The passwords don\'t match. Type the same password again, or press Ctrl+C to start over.'
                : null,
        );

        // Hashed by the model. The role isn't mass assignable, so it is set
        // directly.
        $user->password = $password;
        $user->role = User::ROLE_ADMIN;
        $user->save();

        $this->components->info(match (true) {
            $isNew => "Created an admin account for {$email}.",
            $wasAdmin => "{$email} has a new password.",
            default => "{$email} is now an admin.",
        });

        return self::SUCCESS;
    }

    /**
     * Make an admin a donor again, removing their password.
     */
    private function remove(string $email, ?User $user): int
    {
        if ($user?->role !== User::ROLE_ADMIN) {
            $this->components->error("{$email} isn't an admin.");

            return self::FAILURE;
        }

        if (! $this->confirm("Make {$email} a donor again? Their password will be removed.")) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $user->role = User::ROLE_DONOR;
        $user->password = null;
        $user->save();

        $this->components->info("{$email} is a donor again, without a password.");

        return self::SUCCESS;
    }
}
