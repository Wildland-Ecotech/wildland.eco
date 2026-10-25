<?php

namespace App\Http\Controllers;

use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Subscription;
use App\Models\User;

/**
 * Shows the donation receipt email in the browser with sample details,
 * for local development only (see routes/web.php). Nothing is saved or
 * sent: the records are built in memory.
 */
class DonationReceiptPreviewController extends Controller
{
    public function __invoke(string $frequency = 'one-time'): DonationReceiptMail
    {
        $user = new User(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);

        $donation = new Donation(['amount_cents' => 2500, 'paid_at' => now(), 'status' => Donation::STATUS_SUCCEEDED]);
        $donation->setRelation('user', $user);
        $donation->setRelation('subscription', match ($frequency) {
            'monthly' => new Subscription(['interval' => Subscription::INTERVAL_MONTH]),
            'annual' => new Subscription(['interval' => Subscription::INTERVAL_YEAR]),
            default => null,
        });

        $receipt = new DonationReceipt([
            'receipt_number' => 'WET-' . now()->year . '-K7Q2MX',
            'type' => DonationReceipt::TYPE_SINGLE,
            'tax_year' => now()->year,
            'amount_cents' => 2500,
        ]);
        $receipt->setRelation('donation', $donation);

        return new DonationReceiptMail($receipt);
    }
}
