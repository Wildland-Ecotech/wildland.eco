<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a donor created from the donate page,
     * with an unverified email address and no password.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => null,
            'password' => null,
            'role' => User::ROLE_DONOR,
        ];
    }

    /**
     * Indicate that the user has verified their email address.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the user has set a password ("password").
     */
    public function withPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => static::$password ??= Hash::make('password'),
        ]);
    }
}
