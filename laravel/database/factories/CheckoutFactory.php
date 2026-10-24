<?php

namespace Database\Factories;

use App\Models\Checkout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    /**
     * Define the model's default state: a monthly gift to the General Fund,
     * started through Stripe and not yet paid.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount_cents' => fake()->randomElement([2000, 5000, 10000]),
            'currency' => 'usd',
            'frequency' => Checkout::FREQUENCY_MONTHLY,
            'status' => Checkout::STATUS_STARTED,
            'gateway' => 'stripe',
            'gateway_session_id' => 'cs_test_' . Str::random(24),
            'expires_at' => now()->addDay(),
        ];
    }

    /**
     * Indicate a one-time gift.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => Checkout::FREQUENCY_ONE_TIME,
        ]);
    }

    /**
     * Indicate an annual gift.
     */
    public function annual(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => Checkout::FREQUENCY_ANNUAL,
        ]);
    }

    /**
     * Indicate that the donor paid.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Checkout::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the checkout expired without payment.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Checkout::STATUS_EXPIRED,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
