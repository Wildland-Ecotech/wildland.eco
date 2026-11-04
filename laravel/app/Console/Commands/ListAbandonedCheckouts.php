<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\Checkout;
use App\Reports\AbandonedCheckoutReport;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lists abandoned checkouts: donors who filled in the donate form and went to
 * Stripe's payment page, but left without paying, so the checkout expired.
 * Shows whether each donor donated later anyway. The output contains donors'
 * names and email addresses: never paste it into issues, chat, or the
 * repository.
 */
class ListAbandonedCheckouts extends Command
{
    use DescribesDonations, ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'donations:abandoned
        {--days=30 : Show checkouts started this many days back}
        {--csv : Output CSV with every detail, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'List checkouts donors left without paying';

    /**
     * The table's columns. The amount lines up on the right.
     */
    public const HEADERS = ['Started', 'Donor', 'Type', 'Amount', 'Donated later'];

    public const AMOUNT_COLUMNS = [3];

    public function handle(AbandonedCheckoutReport $report): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $checkouts = $report->list($days);

        if ($this->option('csv')) {
            $this->writeCsv(
                ['Reference', 'Started', 'Name', 'Email', 'Type', 'Amount', 'Currency', 'Donated later'],
                $checkouts->map(fn (Checkout $checkout) => [
                    $checkout->reference,
                    $this->localTime($checkout->created_at),
                    (string) $checkout->user?->name,
                    (string) $checkout->user?->email,
                    $this->checkoutType($checkout),
                    $this->decimal($checkout->amount_cents),
                    strtoupper($checkout->currency),
                    $checkout->donated_later ? 'Yes' : 'No',
                ]),
            );

            return self::SUCCESS;
        }

        if ($checkouts->isEmpty()) {
            $this->components->info("No abandoned checkouts in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->writeTable(self::HEADERS, $checkouts->map(fn (Checkout $checkout) => [
            $this->tableTime($checkout->created_at),
            $this->donor($checkout),
            $this->checkoutType($checkout),
            $this->money($checkout->amount_cents, $checkout->currency),
            $checkout->donated_later ? 'Yes' : 'No',
        ]), self::AMOUNT_COLUMNS);

        $count = $checkouts->count();
        $donatedLater = $checkouts->filter(fn (Checkout $checkout) => $checkout->donated_later)->count();

        $this->components->info("{$count} abandoned " . Str::plural('checkout', $count) . " in the last {$days} days; {$donatedLater} donated later.");

        return self::SUCCESS;
    }
}
