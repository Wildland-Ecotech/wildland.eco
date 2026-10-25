<?php

namespace App\Console\Commands;

use App\Payments\Stripe\StripeImporter;
use App\Payments\Stripe\StripeMode;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Throwable;

/**
 * Imports donations, recurring donations, refunds, and checkouts from
 * Stripe, through the same sync functions as webhooks. Safe to run any
 * number of times. Run in full once per environment to import past
 * history, including donations made through Payment Links; the scheduler
 * runs it nightly for recent days to catch anything a webhook missed.
 *
 * Each object is shown as it syncs, so the last line always shows where a
 * slow or failing sync is, followed by a summary.
 */
class SyncStripe extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stripe:sync
        {--days= : Only import objects created in the last N days (subscriptions are always imported in full)}';

    /**
     * @var string
     */
    protected $description = 'Import donations, subscriptions, refunds, and checkouts from Stripe';

    /**
     * How each kind of object is named on its own line.
     */
    private const LABELS = [
        StripeImporter::SUBSCRIPTIONS => 'Subscription',
        StripeImporter::INVOICES => 'Invoice',
        StripeImporter::PAYMENTS => 'Payment',
        StripeImporter::REFUNDS => 'Refund',
        StripeImporter::CHECKOUT_SESSIONS => 'Checkout session',
    ];

    public function handle(StripeImporter $importer): int
    {
        if (! StripeMode::isConfigured()) {
            $this->components->warn('Stripe is not configured (STRIPE_SECRET is empty), so there is nothing to sync.');

            return self::SUCCESS;
        }

        $days = $this->option('days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->components->error('--days must be a whole number of days, 1 or more.');

            return self::FAILURE;
        }

        $since = $days ? now()->subDays((int) $days) : null;

        $this->components->info(sprintf(
            '%s Stripe sync started at %s UTC: %s.',
            StripeMode::expected() === StripeMode::LIVE ? 'Live' : 'Test mode',
            now()->toDateTimeString(),
            $since ? "objects created since {$since->toDateTimeString()} UTC, and every subscription" : 'all history',
        ));

        $totals = array_fill_keys(StripeImporter::KINDS, ['synced' => 0, 'skipped' => 0, 'failed' => 0]);
        $failures = [];

        $importer->import($since, function (string $kind, string $id, Closure $sync) use (&$totals, &$failures) {
            $this->components->task(self::LABELS[$kind] . " {$id}", function () use ($kind, $id, $sync, &$totals, &$failures) {
                try {
                    $record = $sync();
                } catch (Throwable $exception) {
                    report($exception);
                    $totals[$kind]['failed']++;
                    $failures[] = "{$id} ({$kind}): {$exception->getMessage()}";

                    return TaskResult::Failure->value;
                }

                // Nothing to record, such as a Payment Link's checkout
                // session or a payment that was never attempted
                if ($record === null) {
                    $totals[$kind]['skipped']++;

                    return TaskResult::Skipped->value;
                }

                $totals[$kind]['synced']++;

                return TaskResult::Success->value;
            });
        });

        $this->newLine();
        $this->table(
            ['Stripe objects', 'Synced', 'Skipped', 'Failed'],
            collect($totals)->map(fn ($counts, $kind) => [$kind, $counts['synced'], $counts['skipped'], $counts['failed']])->values()->all(),
        );

        if ($failures) {
            foreach ($failures as $failure) {
                $this->components->error("Failed to sync {$failure}");
            }

            $this->components->error(count($failures) . ' failed; details are also in the log. Running the sync again retries them.');

            return self::FAILURE;
        }

        $this->components->info('Stripe sync finished at ' . now()->toDateTimeString() . ' UTC.');

        return self::SUCCESS;
    }
}
