<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
