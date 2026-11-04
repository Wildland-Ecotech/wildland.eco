<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\Checkout;
use App\Models\Donation;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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

    public function handle(): int
    {
        $days = $this->daysOption();

        if ($days === null) {
            return self::INVALID;
        }

        $checkouts = Checkout::query()
            ->select('checkouts.*')
            ->addSelect(['donated_later' => $this->donatedLater()])
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->where(fn (Builder $query) => $query
                ->where('status', Checkout::STATUS_EXPIRED)
                // Expired without the webhook saying so yet, such as during
                // an outage; the nightly sync catches up on these
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', Checkout::STATUS_STARTED)
                    ->whereNotNull('gateway_session_id')
                    ->where('expires_at', '<', now())))
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

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

    /**
     * Whether the checkout's donor has a donation we received, paid after
     * the checkout was started, as a subquery: 1, or null.
     *
     * @return Builder<Donation>
     */
    private function donatedLater(): Builder
    {
        return Donation::query()
            ->received()
            ->selectRaw('1')
            ->whereColumn('donations.user_id', 'checkouts.user_id')
            ->whereColumn('donations.paid_at', '>=', 'checkouts.created_at')
            ->limit(1);
    }
}
