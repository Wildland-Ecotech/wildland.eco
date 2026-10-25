<?php

namespace App\Jobs;

use App\Models\Donation;
use App\Services\DonationReceipts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a donation's receipt automatically, on the queue. Checks again that
 * the receipt is still due when it runs, so a retry more than 24 hours
 * after the donation, or after the receipt was sent, sends nothing.
 */
class SendDonationReceipt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Donation $donation) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    /**
     * One job per donation at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->donation->id;
    }

    public function handle(DonationReceipts $receipts): void
    {
        $donation = $this->donation->fresh();

        if ($donation && $receipts->isDue($donation)) {
            $receipts->send($donation);
        }
    }
}
