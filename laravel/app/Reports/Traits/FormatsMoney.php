<?php

namespace App\Reports\Traits;

/**
 * Amounts in reports, in commands and admin pages. Money is stored as
 * integer cents with a currency (see the schema conventions), and shown the
 * way donation receipts show it.
 */
trait FormatsMoney
{
    /**
     * An amount for reading, such as "$1,250.00", or "-$1.75" for a negative
     * one. Currencies other than US dollars show their code instead of a
     * dollar sign, such as "20.00 EUR".
     */
    protected function money(int $cents, string $currency = 'usd'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $amount = number_format(abs($cents) / 100, 2);

        return strtolower($currency) === 'usd'
            ? "{$sign}\${$amount}"
            : "{$sign}{$amount} " . strtoupper($currency);
    }

    /**
     * An amount for a spreadsheet: a plain decimal number without a currency
     * sign or thousands separators, such as "1250.00" or "-1.75".
     */
    protected function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
