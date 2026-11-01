<?php

namespace App\Console\Traits;

use App\Models\Donation;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How report commands describe a donation, so donations:list and
 * donations:view show the same type, status, date, and amounts. Query
 * donations with donationRelations() loaded.
 */
trait DescribesDonations
{
    use FormatsMoney;

    /**
     * Labels for a donation's fields, in order.
     */
    protected const DONATION_LABELS = ['Type', 'Status', 'Name', 'Email', 'Amount', 'Stripe fee', 'Refunded', 'Net', 'Designation'];

    /**
     * The related records a donation is described with, for with(),
     * including soft-deleted ones, so a deleted designation or donor still
     * shows its name.
     *
     * @return array<string, callable>
     */
    protected function donationRelations(): array
    {
        $withTrashed = fn ($query) => $query->withTrashed();

        return ['user' => $withTrashed, 'subscription' => $withTrashed, 'designation' => $withTrashed];
    }

    /**
     * A donation's fields, keyed by DONATION_LABELS. Amounts are written for
     * reading, or as plain numbers for a spreadsheet with $csv. The fee,
     * refund, and net are only shown for payments we received; a fee Stripe
     * hasn't reported yet leaves the fee and net empty in a spreadsheet,
     * rather than counting as zero.
     *
     * @return array<string, string>
     */
    protected function donationFields(Donation $donation, bool $csv = false): array
    {
        $amount = fn (int $cents) => $csv ? $this->decimal($cents) : $this->money($cents, $donation->currency);
        $received = $donation->wasReceived();
        $fee = $donation->fee_cents;

        return array_combine(self::DONATION_LABELS, [
            $this->donationType($donation),
            $this->donationStatus($donation),
            (string) $donation->user?->name,
            (string) $donation->user?->email,
            $amount($donation->amount_cents),
            match (true) {
                ! $received => '',
                $fee === null => $csv ? '' : 'Not recorded yet',
                default => $amount($fee),
            },
            $received ? $amount($donation->refunded_cents) : '',
            $received && $fee !== null ? $amount($donation->amount_cents - $donation->refunded_cents - $fee) : '',
            (string) $donation->designation?->name,
        ]);
    }

    /**
     * Who made the donation: their name, or their email address if we
     * don't have a name, such as for some Payment Link donations.
     */
    protected function donor(Donation $donation): string
    {
        return (string) ($donation->user?->name ?: $donation->user?->email);
    }

    /**
     * Whether the payment was a one-time donation, or from a monthly or
     * annual one.
     */
    protected function donationType(Donation $donation): string
    {
        if ($donation->subscription_id === null) {
            return 'One-time';
        }

        return match ($donation->subscription?->interval) {
            Subscription::INTERVAL_MONTH => 'Monthly',
            Subscription::INTERVAL_YEAR => 'Annual',
            default => 'Recurring',
        };
    }

    /**
     * The payment's status, in plain words.
     */
    protected function donationStatus(Donation $donation): string
    {
        return match ($donation->status) {
            Donation::STATUS_SUCCEEDED => 'Paid',
            Donation::STATUS_PENDING => 'Pending',
            Donation::STATUS_FAILED => 'Failed',
            Donation::STATUS_REFUNDED => 'Refunded',
            Donation::STATUS_PARTIALLY_REFUNDED => 'Partially refunded',
            default => $donation->status,
        };
    }

    /**
     * When the payment was made: when it was paid; for a pending or failed
     * payment, when the donor tried to pay (Stripe's creation time of the
     * payment), so payments imported later fall on the right day; else when
     * we recorded it.
     */
    protected function donationMadeAt(Donation $donation): CarbonInterface
    {
        $created = data_get($donation->gateway_data, 'created');

        return $donation->paid_at
            ?? (is_numeric($created) ? CarbonImmutable::createFromTimestamp((int) $created) : $donation->created_at);
    }
}
