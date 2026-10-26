<?php

namespace App\Console\Commands;

use App\Payments\Stripe\StripeMode;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Checks that the Stripe keys match the mode this site must use: live mode
 * only on https://wildland.eco, test mode everywhere else. Deploys run it
 * after caching the configuration, so a wrong key fails the deploy instead
 * of taking payments in the wrong mode. Makes no API calls.
 */
class CheckStripeConfiguration extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stripe:check';

    /**
     * @var string
     */
    protected $description = 'Check that the Stripe keys match the mode this site must use';

    public function handle(): int
    {
        if (! StripeMode::isConfigured()) {
            $this->components->warn('Stripe is not configured (STRIPE_SECRET is empty), so payments are disabled.');

            return self::SUCCESS;
        }

        try {
            StripeMode::assertValid();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $webhookSecret = (string) config('services.stripe.webhook_secret');

        if (! str_starts_with($webhookSecret, 'whsec_')) {
            $this->components->error('STRIPE_WEBHOOK_SECRET must be set to the webhook endpoint\'s signing secret (whsec_).');

            return self::FAILURE;
        }

        foreach (['donation_product_id' => 'STRIPE_DONATION_PRODUCT_ID', 'portal_login_url' => 'STRIPE_PORTAL_LOGIN_URL'] as $key => $name) {
            if (blank(config("services.stripe.{$key}"))) {
                $this->components->error("{$name} must be set.");

                return self::FAILURE;
            }
        }

        $this->components->info(sprintf('Stripe is configured in %s mode for %s.', StripeMode::expected(), config('app.url')));

        return self::SUCCESS;
    }
}
