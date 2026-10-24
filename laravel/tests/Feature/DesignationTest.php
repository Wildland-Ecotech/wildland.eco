<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Designation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_the_general_fund(): void
    {
        $fund = Designation::generalFund();

        $this->assertSame('General Fund', $fund->name);
        $this->assertSame(Designation::GENERAL_FUND_SLUG, $fund->slug);
        $this->assertFalse($fund->is_restricted);
        $this->assertTrue($fund->is_active);
        $this->assertSame(1, Designation::count());
    }

    public function test_the_factory_makes_unrestricted_designations_and_its_state_restricts_them(): void
    {
        $this->assertFalse(Designation::factory()->create()->is_restricted);
        $this->assertTrue(Designation::factory()->restricted()->create()->is_restricted);
    }

    public function test_gifts_without_a_designation_go_to_the_general_fund(): void
    {
        $checkout = Checkout::factory()->create();

        $this->assertTrue($checkout->designation->is(Designation::generalFund()));
        $this->assertTrue(Designation::generalFund()->checkouts->contains($checkout));
    }

    public function test_gifts_keep_the_designation_they_name(): void
    {
        $designation = Designation::factory()->restricted()->create();

        $checkout = Checkout::factory()->create(['designation_id' => $designation->id]);

        $this->assertTrue($checkout->designation->is($designation));
    }

    public function test_a_deleted_designation_is_kept_and_its_slug_stays_reserved(): void
    {
        $designation = Designation::factory()->create(['slug' => 'high-park']);

        $designation->delete();

        $this->assertSoftDeleted($designation);

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        Designation::factory()->create(['slug' => 'high-park']);
    }
}
