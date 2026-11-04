<?php

namespace App\Reports\Traits;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\Subscription;
use App\Reports\DonationReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * How reports describe a donation, so donations:list, donations:view,
 * donations:abandoned, and the admin pages show the same donor, type,
 * status, date, and amounts. Query donations with donationRelations()
 * loaded.
 */
trait DescribesDonations
{
    use FormatsMoney, FormatsTimes;

    /**
     * Labels for a donation's fields, in order.
     */
    protected const DONATION_LABELS = ['Type', 'Status', 'Name', 'Email', 'Amount', 'Stripe fee', 'Refunded', 'Net', 'Designation'];

    /**
     * The columns of a list of donations: the key details of each payment.
     */
    protected const DONATION_TABLE_HEADERS = ['ID', 'Date', 'Donor', 'Type', 'Amount', 'Status'];

    /**
     * A donation's row in a list, matching DONATION_TABLE_HEADERS.
     *
     * @return array<int, mixed>
     */
    protected function donationTableRow(Donation $donation): array
    {
        return [
            $donation->id,
            $this->tableTime($this->donationMadeAt($donation)),
            $this->donor($donation),
            $this->donationType($donation),
            $this->money($donation->amount_cents, $donation->currency),
            $this->donationStatus($donation),
        ];
    }

    /**
     * The header row of a CSV of donations: every detail, plus the currency.
     *
     * @return array<int, string>
     */
    protected function donationCsvHeader(): array
    {
        return ['ID', 'Payment ID', 'Date', ...self::DONATION_LABELS, 'Currency'];
    }

    /**
     * A donation's row in a CSV, matching donationCsvHeader(). Amounts are
     * plain numbers.
     *
     * @return array<int, mixed>
     */
    protected function donationCsvRow(Donation $donation): array
    {
        return [
            $donation->id,
            $donation->gateway_payment_id,
            $this->localTime($this->donationMadeAt($donation)),
            ...array_values($this->donationFields($donation, csv: true)),
            strtoupper($donation->currency),
        ];
    }

    /**
     * What a list of donations holds, such as "monthly and annual donations
     * received", or "payments" when it includes payments never received
     * ($all). $types are DonationReport::TYPES; with none or all of them,
     * no type is named.
     *
     * @param  array<int, string>  $types
     */
    protected function donationListName(array $types, bool $all, int $count): string
    {
        $which = $types === [] || count($types) === count(DonationReport::TYPES) ? '' : Arr::join($types, ', ', ' and ');

        return $all
            ? trim("{$which} " . Str::plural('payment', $count))
            : trim("{$which} " . Str::plural('donation', $count)) . ' received';
    }

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
     * Who made the donation or started the checkout: their name, or their
     * email address if we don't have a name, such as for some Payment Link
     * donations.
     */
    protected function donor(Donation|Checkout $record): string
    {
        return (string) ($record->user?->name ?: $record->user?->email);
    }

    /**
     * What a checkout was for: a one-time, monthly, or annual donation,
     * matching donationType().
     */
    protected function checkoutType(Checkout $checkout): string
    {
        return match ($checkout->frequency) {
            Checkout::FREQUENCY_ONE_TIME => 'One-time',
            Checkout::FREQUENCY_MONTHLY => 'Monthly',
            Checkout::FREQUENCY_ANNUAL => 'Annual',
            default => $checkout->frequency,
        };
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
