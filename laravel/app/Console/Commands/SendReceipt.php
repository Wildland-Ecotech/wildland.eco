<?php

namespace App\Console\Commands;

use App\Models\Donation;
use App\Payments\Stripe\StripeGateway;
use App\Services\DonationReceipts;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends the receipt for one donation, named by its Stripe payment ID, such
 * as one that succeeded more than 24 hours before it was synced and so
 * wasn't receipted automatically. Takes exactly one payment ID and nothing
 * else, so it can never email more than one donor. Asks for confirmation
 * after showing who will receive the receipt.
 */
class SendReceipt extends Command
{
    /**
     * @var string
     */
    protected $signature = 'receipt:send
        {payment : The donation\'s Stripe payment ID (pi_...), from the Stripe dashboard or the donations table}
        {--resend : Send a receipt that was already sent again, with the same receipt number}
        {--force : Send without asking for confirmation}';

    /**
     * @var string
     */
    protected $description = 'Send the receipt for one donation, by its Stripe payment ID';

    public function handle(DonationReceipts $receipts): int
    {
        $paymentId = trim((string) $this->argument('payment'));

        if (! preg_match('/^pi_[A-Za-z0-9]+$/', $paymentId)) {
            $this->components->error('Give one Stripe payment ID, starting with pi_.');

            return self::FAILURE;
        }

        $donation = Donation::where('gateway', StripeGateway::NAME)->where('gateway_payment_id', $paymentId)->first();

        if (! $donation) {
            $this->components->error("No donation has the payment ID {$paymentId}. If it's new, run `php artisan stripe:sync` first.");

            return self::FAILURE;
        }

        if ($donation->status !== Donation::STATUS_SUCCEEDED) {
            $this->components->error("This donation's status is {$donation->status}; only succeeded donations are receipted.");

            return self::FAILURE;
        }

        if ($receipts->wasSent($donation) && ! $this->option('resend')) {
            $receipt = $donation->receipt;

            $this->components->error("Receipt {$receipt->receipt_number} was already sent on {$receipt->sent_at->toDayDateTimeString()} UTC. Use --resend to send it again.");

            return self::FAILURE;
        }

        $email = $donation->user()->withTrashed()->first()->email;

        $this->components->twoColumnDetail('Payment', $paymentId);
        $this->components->twoColumnDetail('Amount', '$' . number_format($donation->amount_cents / 100, 2));
        $this->components->twoColumnDetail('Paid', $donation->paid_at->copy()->timezone(DonationReceipts::TIMEZONE)->toDayDateTimeString() . ' (Fort Collins)');
        $this->components->twoColumnDetail('Send to', $email);

        if (! $this->option('force') && ! $this->confirm("Send this receipt to {$email}?")) {
            $this->components->warn('Nothing was sent.');

            return self::FAILURE;
        }

        try {
            $receipt = $receipts->send($donation, resend: (bool) $this->option('resend'));
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error("Sending failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Sent receipt {$receipt->receipt_number} to {$email}.");

        return self::SUCCESS;
    }
}
