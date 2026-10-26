<?php

namespace Tests\Feature;

use App\Models\GatewayCustomer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_donor_can_have_several_customer_records(): void
    {
        $user = User::factory()->create();

        $first = GatewayCustomer::factory()->for($user)->create();
        GatewayCustomer::factory()->for($user)->create();

        $this->assertCount(2, $user->gatewayCustomers);
        $this->assertTrue($first->user->is($user));
    }

    public function test_finding_by_gateway_id_includes_soft_deleted_customers(): void
    {
        $customer = GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_deleted']);
        $customer->delete();

        $found = GatewayCustomer::findByGatewayId('stripe', 'cus_deleted');

        $this->assertTrue($found->is($customer));
        $this->assertTrue($found->trashed());
        $this->assertNull(GatewayCustomer::findByGatewayId('stripe', 'cus_missing'));
        $this->assertNull(GatewayCustomer::findByGatewayId('other', 'cus_deleted'));
    }

    public function test_a_gateway_customer_id_is_recorded_only_once(): void
    {
        GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_duplicate']);

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_duplicate']);
    }

    public function test_the_database_refuses_to_remove_a_user_with_customer_records(): void
    {
        $customer = GatewayCustomer::factory()->create();

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(QueryException::class);

        $customer->user->forceDelete();
    }
}
