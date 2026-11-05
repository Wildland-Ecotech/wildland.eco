<?php

namespace App\Reports\Traits;

use App\Models\Checkout;

/**
 * How reports describe an abandoned checkout from AbandonedCheckoutReport,
 * so donations:abandoned, its CSV, and the admin abandoned checkouts page
 * show the same rows.
 */
trait DescribesCheckouts
{
    use DescribesDonations;

    /**
     * The columns of a list of abandoned checkouts.
     */
    protected const CHECKOUT_TABLE_HEADERS = ['Started', 'Donor', 'Type', 'Amount', 'Donated later'];

    /**
     * A checkout's row in a list, matching CHECKOUT_TABLE_HEADERS.
     *
     * @return array<int, mixed>
     */
    protected function checkoutTableRow(Checkout $checkout): array
    {
        return [
            $this->tableTime($checkout->created_at),
            $this->donor($checkout),
            $this->checkoutType($checkout),
            $this->money($checkout->amount_cents, $checkout->currency),
            $this->donatedLater($checkout),
        ];
    }

    /**
     * The header row of a CSV of abandoned checkouts, with the donor's name
     * and email address and the currency.
     *
     * @return array<int, string>
     */
    protected function checkoutCsvHeader(): array
    {
        return ['Reference', 'Started', 'Name', 'Email', 'Type', 'Amount', 'Currency', 'Donated later'];
    }

    /**
     * A checkout's row in a CSV, matching checkoutCsvHeader(). Amounts are
     * plain numbers.
     *
     * @return array<int, mixed>
     */
    protected function checkoutCsvRow(Checkout $checkout): array
    {
        return [
            $checkout->reference,
            $this->localTime($checkout->created_at),
            (string) $checkout->user?->name,
            (string) $checkout->user?->email,
            $this->checkoutType($checkout),
            $this->decimal($checkout->amount_cents),
            strtoupper($checkout->currency),
            $this->donatedLater($checkout),
        ];
    }

    /**
     * Whether the donor has a donation we received, paid after the checkout
     * was started: Yes or No, from the `donated_later` attribute that
     * AbandonedCheckoutReport adds.
     */
    protected function donatedLater(Checkout $checkout): string
    {
        return $checkout->donated_later ? 'Yes' : 'No';
    }
}
