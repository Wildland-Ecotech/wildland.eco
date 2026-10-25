<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    /**
     * Define the model's default state: a succeeded one-time Stripe payment
     * to the General Fund.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gateway' => 'stripe',
            'gateway_payment_id' => 'pi_' . Str::random(24),
            'amount_cents' => 5000,
            'fee_cents' => 175,
            'refunded_cents' => 0,
            'currency' => 'usd',
            'status' => Donation::STATUS_SUCCEEDED,
            'paid_at' => now(),
        ];
    }

    /**
     * Indicate a payment still being processed.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Donation::STATUS_PENDING,
            'fee_cents' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Indicate a payment that failed, such as a declined card.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Donation::STATUS_FAILED,
            'fee_cents' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Indicate a received payment that was refunded in full.
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Donation::STATUS_REFUNDED,
            'refunded_cents' => $attributes['amount_cents'],
        ]);
    }

    /**
     * Indicate a received payment that was refunded in part.
     */
    public function partiallyRefunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Donation::STATUS_PARTIALLY_REFUNDED,
            'refunded_cents' => intdiv($attributes['amount_cents'], 2),
        ]);
    }
}
