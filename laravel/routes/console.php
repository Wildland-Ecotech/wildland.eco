<?php

use App\Payments\Stripe\StripeMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catch anything a Stripe webhook missed. Three days covers a weekend of
// webhook failures; a longer outage needs a manual `stripe:sync --days=N`.
// Each run's line-by-line output is appended to its own log, since nobody
// watches it run.
Schedule::command('stripe:sync --days=3')
    ->dailyAt('03:30')
    ->timezone('America/Denver')
    ->withoutOverlapping()
    ->when(fn () => StripeMode::isConfigured())
    ->appendOutputTo(storage_path('logs/stripe-sync.log'));
