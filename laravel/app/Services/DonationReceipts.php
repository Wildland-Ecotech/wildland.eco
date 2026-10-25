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
     * Receipt numbers are WET-<tax year>-<code>, such as WET-2026-K7Q2MX,
     * with a random code rather than a sequence, so a number reveals nothing
     * about how many donations we receive. "WET" is short for Wildland
     * Ecology Technologies, and is only used in receipt numbers.
     */
    public const PREFIX = 'WET';

    /**
     * The characters of the random code: digits and capital letters without
     * the lookalikes 0, 1, I, L, and O, so a number can be read aloud or
     * typed without mistakes. 31 characters, six long, gives over 887
     * million codes per tax year.
     */
    public const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const CODE_LENGTH = 6;

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
     * Arbitrary key for the database lock that serializes issuing receipts,
     * so two attempts for the same donation never both create one.
     */
    private const ISSUE_LOCK = 8_211_734;

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
     * The donation's receipt, issued with a new number for its tax year if
     * it doesn't have one. Receipts are issued one at a time under a database
     * lock, and a number already used is drawn again, so two receipts can
     * never get the same number.
     */
    public function issue(Donation $donation): DonationReceipt
    {
        return DB::transaction(function () use ($donation) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::ISSUE_LOCK]);

            $existing = DonationReceipt::withTrashed()
                ->where('donation_id', $donation->id)
                ->where('type', DonationReceipt::TYPE_SINGLE)
                ->first();

            if ($existing) {
                return $existing;
            }

            $taxYear = $donation->paid_at->copy()->timezone(self::TIMEZONE)->year;

            // Draws again in the rare case the number was ever issued,
            // including to a deleted receipt, so a number is never reused
            do {
                $number = sprintf('%s-%d-%s', self::PREFIX, $taxYear, $this->randomCode());
            } while (DonationReceipt::withTrashed()->where('receipt_number', $number)->exists());

            return DonationReceipt::create([
                'user_id' => $donation->user_id,
                'donation_id' => $donation->id,
                'receipt_number' => $number,
                'type' => DonationReceipt::TYPE_SINGLE,
                'tax_year' => $taxYear,
                'amount_cents' => $donation->amount_cents,
            ]);
        });
    }

    /**
     * A random code for a receipt number, from a cryptographically secure
     * source, so numbers can't be predicted.
     */
    protected function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }
}
