<?php

namespace App\Services;

use App\Jobs\SendDonationReceipt;
use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\DonationReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Tax receipts for donations: one per donation, never more.
 *
 * Receipts are sent automatically only for donations that succeeded within
 * the last 24 hours, so an import of past history, or a bug, can never
 * email donors in bulk. Anything older needs `php artisan receipt:send`
 * with its Stripe payment ID. A receipt's number is issued once and kept,
 * and the database refuses a second receipt for the same donation.
 */
class DonationReceipts
{
    /**
     * Receipt numbers are WET-<tax year>-<sequence>, such as WET-2026-00001,
     * with the sequence restarting each tax year. "WET" is short for
     * Wildland Ecology Technologies, and is only used in receipt numbers.
     */
    public const PREFIX = 'WET';

    /**
     * Dates and tax years are in Fort Collins time, so a donation on the
     * evening of December 31st counts toward that year.
     */
    public const TIMEZONE = 'America/Denver';

    /**
     * How recently a donation must have succeeded to be receipted
     * automatically.
     */
    public const AUTOMATIC_WINDOW_HOURS = 24;

    /**
     * Arbitrary key for the database lock that serializes issuing receipt
     * numbers.
     */
    private const NUMBER_LOCK = 8_211_734;

    /**
     * Queue a donation's receipt if it should be sent automatically. Called
     * by payment sync each time a donation is synced, so most calls do
     * nothing.
     */
    public function queueIfDue(Donation $donation): void
    {
        if ($this->isDue($donation)) {
            SendDonationReceipt::dispatch($donation)->afterCommit();
        }
    }

    /**
     * Whether a donation should be receipted automatically: it succeeded
     * within the last 24 hours, and its receipt hasn't been sent. A donation
     * already refunded before its receipt went out isn't receipted.
     */
    public function isDue(Donation $donation): bool
    {
        return $donation->status === Donation::STATUS_SUCCEEDED
            && $donation->paid_at
            && $donation->paid_at->greaterThanOrEqualTo(now()->subHours(self::AUTOMATIC_WINDOW_HOURS))
            && ! $this->wasSent($donation);
    }

    /**
     * Whether a donation's receipt has been sent.
     */
    public function wasSent(Donation $donation): bool
    {
        return DonationReceipt::withTrashed()
            ->where('donation_id', $donation->id)
            ->where('type', DonationReceipt::TYPE_SINGLE)
            ->whereNotNull('sent_at')
            ->exists();
    }

    /**
     * Send a donation's receipt, issuing its number the first time. A
     * receipt already sent isn't sent again unless $resend is true, which
     * sends the same receipt, with the same number, once more.
     */
    public function send(Donation $donation, bool $resend = false): DonationReceipt
    {
        $receipt = $this->issue($donation);

        if ($receipt->sent_at && ! $resend) {
            return $receipt;
        }

        Mail::to($donation->user()->withTrashed()->first()->email)->send(new DonationReceiptMail($receipt));

        $receipt->update(['sent_at' => now()]);

        return $receipt;
    }

    /**
     * The donation's receipt, issued with the next number for its tax year
     * if it doesn't have one. Numbers are issued one at a time under a
     * database lock, so two receipts can never get the same number.
     */
    public function issue(Donation $donation): DonationReceipt
    {
        return DB::transaction(function () use ($donation) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::NUMBER_LOCK]);

            $existing = DonationReceipt::withTrashed()
                ->where('donation_id', $donation->id)
                ->where('type', DonationReceipt::TYPE_SINGLE)
                ->first();

            if ($existing) {
                return $existing;
            }

            $taxYear = $donation->paid_at->copy()->timezone(self::TIMEZONE)->year;

            $last = DonationReceipt::withTrashed()
                ->where('tax_year', $taxYear)
                ->max(DB::raw("CAST(split_part(receipt_number, '-', 3) AS INTEGER)"));

            return DonationReceipt::create([
                'user_id' => $donation->user_id,
                'donation_id' => $donation->id,
                'receipt_number' => sprintf('%s-%d-%05d', self::PREFIX, $taxYear, ((int) $last) + 1),
                'type' => DonationReceipt::TYPE_SINGLE,
                'tax_year' => $taxYear,
                'amount_cents' => $donation->amount_cents,
            ]);
        });
    }
}
