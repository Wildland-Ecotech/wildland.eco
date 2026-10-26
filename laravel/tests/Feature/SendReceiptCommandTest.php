<?php

namespace Tests\Feature;

use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendReceiptCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * A succeeded donation paid long enough ago that it wasn't receipted
     * automatically.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function donation(array $attributes = []): Donation
    {
        $user = User::factory()->create(['email' => 'jamie@example.com']);

        return Donation::factory()->for($user)->create(array_merge([
            'gateway_payment_id' => 'pi_old',
            'amount_cents' => 4000,
            'paid_at' => now()->subDays(3),
        ], $attributes));
    }

    public function test_one_receipt_is_sent_after_confirmation(): void
    {
        $this->donation();

        // Each line of output only counts toward the first expectation it
        // matches, so no expected text here may appear in another line
        $this->artisan('receipt:send', ['payment' => 'pi_old'])
            ->expectsOutputToContain('$40.00')
            ->expectsConfirmation('Send this receipt to jamie@example.com?', 'yes')
            ->expectsOutputToContain('Sent receipt WET-')
            ->assertSuccessful();

        $this->assertMatchesRegularExpression('/^WET-\d{4}-[2-9A-HJKMNP-Z]{6}$/', DonationReceipt::sole()->receipt_number);

        Mail::assertSent(DonationReceiptMail::class, 1);
        Mail::assertSent(DonationReceiptMail::class, fn ($mail) => $mail->hasTo('jamie@example.com'));
    }

    public function test_nothing_is_sent_without_confirmation(): void
    {
        $this->donation();

        $this->artisan('receipt:send', ['payment' => 'pi_old'])
            ->expectsConfirmation('Send this receipt to jamie@example.com?', 'no')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_force_skips_the_confirmation(): void
    {
        $this->donation();

        $this->artisan('receipt:send', ['payment' => 'pi_old', '--force' => true])->assertSuccessful();

        Mail::assertSent(DonationReceiptMail::class, 1);
    }

    public function test_only_a_single_stripe_payment_id_is_accepted(): void
    {
        $this->donation();

        foreach (['', 'ch_123', 'cus_123', 'pi_old pi_other', 'pi_old,pi_other', 'pi_*', '%', '1'] as $payment) {
            $this->artisan('receipt:send', ['payment' => $payment, '--force' => true])->assertFailed();
        }

        Mail::assertNothingSent();
    }

    public function test_unknown_and_unsucceeded_payments_are_refused(): void
    {
        $this->donation(['status' => Donation::STATUS_FAILED, 'gateway_payment_id' => 'pi_failed']);

        $this->artisan('receipt:send', ['payment' => 'pi_missing', '--force' => true])
            ->expectsOutputToContain('No donation has the payment ID pi_missing')
            ->assertFailed();

        $this->artisan('receipt:send', ['payment' => 'pi_failed', '--force' => true])
            ->expectsOutputToContain('only succeeded donations are receipted')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_a_receipt_already_sent_needs_resend_to_send_again(): void
    {
        $this->donation();
        $this->artisan('receipt:send', ['payment' => 'pi_old', '--force' => true])->assertSuccessful();

        $this->artisan('receipt:send', ['payment' => 'pi_old', '--force' => true])
            ->expectsOutputToContain('was already sent')
            ->assertFailed();

        Mail::assertSent(DonationReceiptMail::class, 1);

        $this->artisan('receipt:send', ['payment' => 'pi_old', '--resend' => true, '--force' => true])
            ->expectsOutputToContain('Sent receipt WET-')
            ->assertSuccessful();

        Mail::assertSent(DonationReceiptMail::class, 2);
    }
}
