<?php

namespace Tests\Feature;

use App\Http\Controllers\DonationReceiptPreviewController;
use App\Jobs\SendDonationReceipt;
use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Stripe\StripeSync;
use App\Services\DonationReceipts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

class DonationReceiptsTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-25 12:00', 'America/Denver'));
    }

    private function receipts(): DonationReceipts
    {
        return app(DonationReceipts::class);
    }

    /**
     * A succeeded $25 one-time donation, paid an hour ago unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function donation(array $attributes = []): Donation
    {
        $user = User::factory()->create(['name' => 'Jamie Rivera']);

        return Donation::factory()->for($user)->create(array_merge(['amount_cents' => 2500, 'paid_at' => now()->subHour()], $attributes));
    }

    public function test_a_recent_donation_is_receipted_once(): void
    {
        $donation = $this->donation();

        $this->receipts()->queueIfDue($donation);
        $this->receipts()->queueIfDue($donation->fresh());

        Mail::assertSent(DonationReceiptMail::class, 1);
        Mail::assertSent(DonationReceiptMail::class, fn ($mail) => $mail->hasTo($donation->user->email));

        $receipt = DonationReceipt::sole();

        $this->assertSame('WET-2026-00001', $receipt->receipt_number);
        $this->assertSame(DonationReceipt::TYPE_SINGLE, $receipt->type);
        $this->assertSame(2026, $receipt->tax_year);
        $this->assertSame(2500, $receipt->amount_cents);
        $this->assertTrue($receipt->user->is($donation->user));
        $this->assertTrue($donation->fresh()->receipt->is($receipt));
        $this->assertNotNull($receipt->sent_at);
        $this->assertFalse($this->receipts()->isDue($donation));
    }

    public function test_only_donations_from_the_last_24_hours_are_receipted_automatically(): void
    {
        $this->assertTrue($this->receipts()->isDue($this->donation(['paid_at' => now()->subHours(23)])));
        $this->assertFalse($this->receipts()->isDue($this->donation(['paid_at' => now()->subHours(25)])));
        $this->assertFalse($this->receipts()->isDue($this->donation(['paid_at' => null])));

        $this->receipts()->queueIfDue($this->donation(['paid_at' => now()->subDays(30)]));

        Mail::assertNothingSent();
    }

    public function test_only_succeeded_donations_are_receipted_automatically(): void
    {
        foreach (['failed', 'pending', 'refunded', 'partiallyRefunded'] as $state) {
            $donation = Donation::factory()->{$state}()->create(['paid_at' => now()->subHour()]);

            $this->assertFalse($this->receipts()->isDue($donation), $state);
        }
    }

    public function test_receipt_numbers_follow_the_tax_year_in_fort_collins(): void
    {
        // The evening of December 31st in Fort Collins is already January
        // 1st in UTC, and still counts toward that year
        $december = $this->receipts()->issue($this->donation(['paid_at' => Carbon::parse('2026-12-31 22:00', 'America/Denver')]));
        $second = $this->receipts()->issue($this->donation(['paid_at' => Carbon::parse('2026-12-31 23:00', 'America/Denver')]));
        $january = $this->receipts()->issue($this->donation(['paid_at' => Carbon::parse('2027-01-01 08:00', 'America/Denver')]));

        $this->assertSame('WET-2026-00001', $december->receipt_number);
        $this->assertSame(2026, $december->tax_year);
        $this->assertSame('WET-2026-00002', $second->receipt_number);
        $this->assertSame('WET-2027-00001', $january->receipt_number);
    }

    public function test_a_deleted_receipts_number_is_never_reused(): void
    {
        $this->receipts()->issue($this->donation())->delete();

        $this->assertSame('WET-2026-00002', $this->receipts()->issue($this->donation())->receipt_number);
    }

    public function test_the_database_refuses_a_second_receipt_for_a_donation(): void
    {
        $donation = $this->donation();
        $this->receipts()->issue($donation);

        $this->expectException(UniqueConstraintViolationException::class);

        DonationReceipt::create([
            'user_id' => $donation->user_id,
            'donation_id' => $donation->id,
            'receipt_number' => 'WET-2026-99999',
            'type' => DonationReceipt::TYPE_SINGLE,
            'tax_year' => 2026,
            'amount_cents' => 2500,
        ]);
    }

    public function test_a_receipt_issued_but_not_sent_is_sent_on_the_next_try(): void
    {
        // Such as when the email failed after the number was issued
        $donation = $this->donation();
        $issued = $this->receipts()->issue($donation);

        $this->assertNull($issued->sent_at);
        $this->assertTrue($this->receipts()->isDue($donation));

        $sent = $this->receipts()->send($donation);

        $this->assertTrue($sent->is($issued));
        $this->assertNotNull($sent->sent_at);
        Mail::assertSent(DonationReceiptMail::class, 1);
    }

    public function test_resending_sends_the_same_receipt_again(): void
    {
        $donation = $this->donation();
        $first = $this->receipts()->send($donation);

        $this->receipts()->send($donation);
        Mail::assertSent(DonationReceiptMail::class, 1);

        $again = $this->receipts()->send($donation, resend: true);

        Mail::assertSent(DonationReceiptMail::class, 2);
        $this->assertSame($first->receipt_number, $again->receipt_number);
        $this->assertSame(1, DonationReceipt::count());
    }

    public function test_a_queued_receipt_checks_again_that_it_is_still_due(): void
    {
        $donation = $this->donation();
        $job = new SendDonationReceipt($donation);

        $this->travel(2)->days();
        app()->call([$job, 'handle']);

        Mail::assertNothingSent();
        $this->assertSame((string) $donation->id, $job->uniqueId());
    }

    public function test_payment_sync_receipts_a_new_donation_once(): void
    {
        $user = User::factory()->create();
        $user->gatewayCustomers()->create(['gateway' => 'stripe', 'gateway_customer_id' => 'cus_receipt']);

        foreach (range(1, 2) as $i) {
            $this->queueStripeResponse(200, [
                'id' => 'pi_receipt', 'object' => 'payment_intent', 'amount' => 5000, 'currency' => 'usd',
                'status' => 'succeeded', 'customer' => 'cus_receipt', 'metadata' => [], 'livemode' => false,
                'latest_charge' => ['id' => 'ch_receipt', 'object' => 'charge', 'amount_refunded' => 0, 'created' => now()->subMinutes(5)->timestamp],
            ]);
            $this->queueStripeResponse(200, ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => false, 'data' => []]);

            app(StripeSync::class)->paymentIntent('pi_receipt');
        }

        Mail::assertSent(DonationReceiptMail::class, 1);
        $this->assertSame(5000, DonationReceipt::sole()->amount_cents);
    }

    public function test_the_receipt_has_everything_donors_need_for_their_taxes(): void
    {
        $mail = new DonationReceiptMail($this->receipts()->issue($this->donation()));

        $mail->assertHasSubject('Your donation receipt from Wildland Ecotech (WET-2026-00001)');
        $mail->assertSeeInOrderInHtml(['Dear Jamie Rivera,', 'WET-2026-00001', 'October 25, 2026', '$25.00', 'One-time donation'], false);
        $mail->assertSeeInHtml('No goods or services were provided in exchange for this contribution.');
        $mail->assertSeeInHtml('Wildland Ecology Technologies');
        $mail->assertSeeInHtml('242 Linden Street');
        $mail->assertSeeInHtml('Fort Collins, CO 80524');
        $mail->assertSeeInHtml('EIN: 33-3738514');
        $mail->assertSeeInHtml('80+ volunteers');
        $mail->assertSeeInText('No goods or services were provided in exchange for this contribution.');
        $mail->assertDontSeeInHtml('gift');
        $mail->assertDontSeeInHtml('Manage Your Recurring Donation');
    }

    public function test_recurring_receipts_link_to_the_customer_portal(): void
    {
        config(['services.stripe.portal_login_url' => 'https://billing.stripe.com/p/login/test_example']);

        $donation = $this->donation();
        $donation->update(['subscription_id' => Subscription::factory()->for($donation->user)->create()->id]);

        $mail = new DonationReceiptMail($this->receipts()->issue($donation->fresh()));

        $mail->assertSeeInHtml('Monthly recurring donation');
        $mail->assertSeeInHtml('Manage Your Recurring Donation');
        $mail->assertSeeInHtml('https://billing.stripe.com/p/login/test_example');
    }

    public function test_a_donor_without_a_name_is_greeted_without_one(): void
    {
        $donation = $this->donation();
        $donation->user->update(['name' => null]);

        (new DonationReceiptMail($this->receipts()->issue($donation->fresh())))->assertSeeInHtml('Hello,');
    }

    public function test_the_receipt_uses_the_wildland_ecotech_theme(): void
    {
        $mail = new DonationReceiptMail($this->receipts()->issue($this->donation()));

        // The wordmark from the header override, and the theme's page
        // background, inlined into the HTML
        $mail->assertSeeInHtml('class="eco"', false);
        $mail->assertSeeInHtml('#010902', false);
    }

    public function test_the_receipt_preview_is_only_available_locally(): void
    {
        // Tests don't run with APP_ENV=local, so the route isn't registered
        $this->get('/dev/receipt-preview')->assertNotFound();

        $preview = (new DonationReceiptPreviewController)('monthly');

        $preview->assertSeeInHtml('WET-2026-00001');
        $preview->assertSeeInHtml('Monthly recurring donation');
        $this->assertSame(0, DonationReceipt::count());
        Mail::assertNothingSent();
    }
}
