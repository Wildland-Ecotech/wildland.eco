<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_payments_do_not_protect_an_account_from_deletion(): void
    {
        // A spam account from a carding attack: declined cards, unpaid
        // checkouts, and a subscription that never collected a payment
        $user = User::factory()->create();
        $donations = Donation::factory()->count(3)->failed()->for($user)->create();
        $checkouts = Checkout::factory()->count(3)->for($user)->create();
        $subscription = Subscription::factory()->incomplete()->for($user)->create();

        $this->assertFalse($user->hasTransactionHistory());

        $user->delete();

        $this->assertSoftDeleted($user);
        $donations->each(fn ($donation) => $this->assertSoftDeleted($donation));
        $checkouts->each(fn ($checkout) => $this->assertSoftDeleted($checkout));
        $this->assertSoftDeleted($subscription);
        $this->assertSame(0, Donation::count());
    }

    public function test_deleting_a_user_leaves_other_donors_alone(): void
    {
        $spam = User::factory()->create();
        Donation::factory()->failed()->for($spam)->create();
        $other = Donation::factory()->failed()->create();

        $spam->delete();

        $this->assertNotSoftDeleted($other);
    }

    public function test_a_user_with_a_received_payment_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $donation = Donation::factory()->for($user)->create();
        $failed = Donation::factory()->failed()->for($user)->create();

        $this->assertTrue($user->hasTransactionHistory());
        $this->assertThrows(fn () => $user->delete(), LogicException::class);

        $this->assertNotSoftDeleted($user);
        $this->assertNotSoftDeleted($donation);
        $this->assertNotSoftDeleted($failed);
    }

    public function test_a_refunded_payment_still_counts_as_transaction_history(): void
    {
        $refunded = User::factory()->create();
        Donation::factory()->refunded()->for($refunded)->create();

        $partial = User::factory()->create();
        Donation::factory()->partiallyRefunded()->for($partial)->create();

        $this->assertThrows(fn () => $refunded->delete(), LogicException::class);
        $this->assertThrows(fn () => $partial->delete(), LogicException::class);
    }

    public function test_a_soft_deleted_payment_still_counts_as_transaction_history(): void
    {
        $user = User::factory()->create();
        Donation::factory()->for($user)->create()->delete();

        $this->assertTrue($user->hasTransactionHistory());
        $this->assertThrows(fn () => $user->delete(), LogicException::class);
    }

    public function test_a_pending_payment_is_not_transaction_history(): void
    {
        $user = User::factory()->create();
        Donation::factory()->pending()->for($user)->create();

        $this->assertFalse($user->hasTransactionHistory());
    }

    public function test_a_user_with_transaction_history_cannot_be_hard_deleted_either(): void
    {
        $user = User::factory()->create();
        Donation::factory()->for($user)->create();

        $this->assertThrows(fn () => $user->forceDelete(), LogicException::class);

        $this->assertModelExists($user);
    }
}
