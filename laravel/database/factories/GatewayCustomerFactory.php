<?php

namespace Database\Factories;

use App\Models\GatewayCustomer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GatewayCustomer>
 */
class GatewayCustomerFactory extends Factory
{
    /**
     * Define the model's default state: a Stripe customer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gateway' => 'stripe',
            'gateway_customer_id' => 'cus_' . Str::random(14),
        ];
    }
}
