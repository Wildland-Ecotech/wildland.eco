<?php

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\Stripe\StripeGateway;
use App\Payments\Stripe\StripeMode;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Checked when Stripe is first used rather than at boot, so a wrong
        // key can't stop artisan commands, including the deploy's own
        // `config:cache` and `stripe:check`
        $this->app->singleton(StripeClient::class, function () {
            StripeMode::assertValid();

            return new StripeClient([
                'api_key' => config('services.stripe.secret'),
                'stripe_version' => config('services.stripe.api_version'),
            ]);
        });

        $this->app->bind(PaymentGateway::class, StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
