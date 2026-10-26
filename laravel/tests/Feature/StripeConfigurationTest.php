<?php

namespace Tests\Feature;

use App\Payments\Stripe\StripeMode;
use RuntimeException;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeConfigurationTest extends TestCase
{
    /**
     * Configure Stripe as a server would, with placeholder keys.
     */
    private function configure(string $appUrl, ?string $secret, array $overrides = []): void
    {
        config(array_merge([
            'app.url' => $appUrl,
            'services.stripe.secret' => $secret,
            'services.stripe.webhook_secret' => 'whsec_example',
            'services.stripe.donation_product_id' => 'prod_example',
            'services.stripe.portal_login_url' => 'https://billing.stripe.com/p/login/test_example',
        ], $overrides));
    }

    public function test_only_the_production_site_uses_live_mode(): void
    {
        foreach ([
            'https://wildland.eco' => StripeMode::LIVE,
            'https://WILDLAND.eco/' => StripeMode::LIVE,
            'https://stage.wildland.eco' => StripeMode::TEST,
            'https://prod.wildland.eco' => StripeMode::TEST,
            'http://localhost:8080' => StripeMode::TEST,
        ] as $url => $mode) {
            config(['app.url' => $url]);

            $this->assertSame($mode, StripeMode::expected(), $url);
        }

        config(['app.url' => 'https://wildland.eco']);
        $this->assertTrue(StripeMode::expectsLivemode());
    }

    public function test_key_prefixes_identify_their_mode(): void
    {
        $this->assertSame(StripeMode::LIVE, StripeMode::ofKey('sk_live_example'));
        $this->assertSame(StripeMode::LIVE, StripeMode::ofKey('rk_live_example'));
        $this->assertSame(StripeMode::TEST, StripeMode::ofKey('sk_test_example'));
        $this->assertSame(StripeMode::TEST, StripeMode::ofKey('rk_test_example'));
        $this->assertNull(StripeMode::ofKey('pk_test_example'));
        $this->assertNull(StripeMode::ofKey(null));
    }

    public function test_staging_refuses_a_live_key_without_revealing_it(): void
    {
        $this->configure('https://stage.wildland.eco', 'sk_live_secretvalue');

        $this->assertThrows(
            fn () => StripeMode::assertValid(),
            fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'live mode key')
                && ! str_contains($exception->getMessage(), 'secretvalue'),
        );
    }

    public function test_production_refuses_a_test_key(): void
    {
        $this->configure('https://wildland.eco', 'sk_test_example');

        $this->assertThrows(fn () => StripeMode::assertValid(), RuntimeException::class, 'must use live mode');
    }

    public function test_a_publishable_key_is_refused(): void
    {
        $this->configure('http://localhost:8080', 'pk_test_example');

        $this->assertThrows(fn () => StripeMode::assertValid(), RuntimeException::class, 'not a Stripe secret');
    }

    public function test_the_stripe_client_is_only_created_with_a_key_for_this_sites_mode(): void
    {
        $this->configure('https://stage.wildland.eco', 'sk_live_example');
        $this->assertThrows(fn () => app(StripeClient::class), RuntimeException::class);

        $this->configure('https://stage.wildland.eco', null);
        $this->assertThrows(fn () => app(StripeClient::class), RuntimeException::class, 'not configured');

        $this->configure('https://stage.wildland.eco', 'sk_test_example');
        $this->assertInstanceOf(StripeClient::class, app(StripeClient::class));
    }

    public function test_the_check_passes_without_stripe_configured(): void
    {
        $this->configure('https://stage.wildland.eco', null);

        $this->artisan('stripe:check')
            ->expectsOutputToContain('payments are disabled')
            ->assertSuccessful();
    }

    public function test_the_check_passes_with_matching_keys(): void
    {
        $this->configure('https://wildland.eco', 'sk_live_example');

        $this->artisan('stripe:check')
            ->expectsOutputToContain('live mode for https://wildland.eco')
            ->assertSuccessful();
    }

    public function test_the_check_fails_with_a_key_for_the_wrong_mode(): void
    {
        $this->configure('https://wildland.eco', 'sk_test_example');

        $this->artisan('stripe:check')
            ->expectsOutputToContain('must use live mode')
            ->assertFailed();
    }

    public function test_the_check_fails_when_a_setting_is_missing(): void
    {
        foreach ([
            'services.stripe.webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
            'services.stripe.donation_product_id' => 'STRIPE_DONATION_PRODUCT_ID',
            'services.stripe.portal_login_url' => 'STRIPE_PORTAL_LOGIN_URL',
        ] as $key => $name) {
            $this->configure('https://stage.wildland.eco', 'sk_test_example', [$key => null]);

            $this->artisan('stripe:check')
                ->expectsOutputToContain($name)
                ->assertFailed();
        }
    }
}
