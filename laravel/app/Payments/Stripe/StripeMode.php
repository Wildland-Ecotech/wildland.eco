<?php

namespace App\Payments\Stripe;

use RuntimeException;

/**
 * Keeps Stripe's test and live modes apart. Only the production site,
 * https://wildland.eco, may use live mode; staging and local development must
 * use test mode. The mode comes from APP_URL rather than a separate setting,
 * since staging deliberately runs with APP_ENV=production.
 *
 * The secret key's prefix must match the expected mode, and sync code
 * rejects any Stripe object or event whose livemode flag doesn't match.
 */
final class StripeMode
{
    public const TEST = 'test';

    public const LIVE = 'live';

    /**
     * The only host that may use live mode.
     */
    public const LIVE_HOST = 'wildland.eco';

    /**
     * The mode this site must use, from the host in APP_URL.
     */
    public static function expected(): string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === self::LIVE_HOST ? self::LIVE : self::TEST;
    }

    /**
     * Whether Stripe objects and events must be live mode ones.
     */
    public static function expectsLivemode(): bool
    {
        return self::expected() === self::LIVE;
    }

    /**
     * The mode of a secret or restricted API key, or null if it isn't one.
     */
    public static function ofKey(?string $key): ?string
    {
        return match (true) {
            $key === null => null,
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => self::LIVE,
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => self::TEST,
            default => null,
        };
    }

    /**
     * Whether a secret key is set. Payments are disabled without one.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Throw unless a secret key is set and matches the mode this site must
     * use. The message never includes the key.
     *
     * @throws RuntimeException
     */
    public static function assertValid(): void
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Stripe is not configured: STRIPE_SECRET is empty.');
        }

        $mode = self::ofKey(config('services.stripe.secret'));
        $expected = self::expected();

        if ($mode === null) {
            throw new RuntimeException('STRIPE_SECRET is not a Stripe secret (sk_) or restricted (rk_) key.');
        }

        if ($mode !== $expected) {
            throw new RuntimeException(sprintf(
                'STRIPE_SECRET is a %s mode key, but %s must use %s mode. Only https://%s may use live mode.',
                $mode,
                config('app.url'),
                $expected,
                self::LIVE_HOST,
            ));
        }
    }
}
