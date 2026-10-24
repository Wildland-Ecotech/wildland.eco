<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state: an active monthly Stripe
     * subscription to the General Fund.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_' . Str::random(24),
            'amount_cents' => fake()->randomElement([2000, 5000, 10000]),
            'currency' => 'usd',
            'interval' => Subscription::INTERVAL_MONTH,
            'status' => Subscription::STATUS_ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addMonth(),
        ];
    }

    /**
     * Indicate an annual recurring gift.
     */
    public function annual(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Subscription::INTERVAL_YEAR,
            'current_period_end' => now()->addYear(),
        ]);
    }

    /**
     * Indicate a subscription whose first payment has not succeeded.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_INCOMPLETE,
        ]);
    }

    /**
     * Indicate a canceled recurring gift.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_CANCELED,
            'canceled_at' => now(),
        ]);
    }
}
