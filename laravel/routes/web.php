<?php

use App\Http\Controllers\DonationController;
use App\Http\Controllers\DonationReceiptPreviewController;
use App\Http\Controllers\FormTokenController;
use App\Http\Controllers\LeafApplicationController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\RecordFormSubmission;
use App\Models\FormSubmission;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::view('/', 'pages.home')->name('home');
Route::view('/menu', 'pages.menu')->name('menu');
Route::get('/donate', [DonationController::class, 'create'])->name('donate');
Route::post('/donate', [DonationController::class, 'store'])
    ->middleware(RecordFormSubmission::class . ':' . FormSubmission::FORM_DONATION)
    ->name('donate.store');
Route::get('/donate/thank-you/{checkout:reference}', [DonationController::class, 'thankYou'])->name('donate.thank-you');
Route::view('/leaves', 'pages.leaves')->name('leaves');
Route::get('/leaves/apply', [LeafApplicationController::class, 'create'])->name('leaves.apply');
Route::post('/leaves/apply', [LeafApplicationController::class, 'store'])
    ->middleware(RecordFormSubmission::class . ':' . FormSubmission::FORM_LEAF_APPLICATION)
    ->name('leaves.apply.store');
Route::view('/faq', 'pages.faq')->name('faq');

// Fresh CSRF token and proof-of-work challenge for a form that has been open
// a while; see resources/js/proof-of-work.js
Route::get('/form-tokens', FormTokenController::class)
    ->middleware('throttle:30,1')
    ->name('form-tokens');

// Called by Stripe, which can't send a CSRF token; requests are verified by
// their signature instead. Webhooks have no visitor, so they also skip the
// session and cookies.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
    ])
    ->name('webhooks.stripe');

// Local development only: the donation receipt email as donors see it, with
// sample details, since AhaSend's sandbox records emails without delivering
// them. Staging and production run with APP_ENV=production.
if (app()->environment('local')) {
    Route::get('/dev/receipt-preview/{frequency?}', DonationReceiptPreviewController::class)
        ->whereIn('frequency', ['one-time', 'monthly', 'annual']);
}
