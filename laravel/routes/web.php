<?php

use App\Http\Controllers\Admin\AbandonedCheckoutController as AdminAbandonedCheckoutController;
use App\Http\Controllers\Admin\ApplicationContactController as AdminApplicationContactController;
use App\Http\Controllers\Admin\ContactActivityController as AdminContactActivityController;
use App\Http\Controllers\Admin\ContactController as AdminContactController;
use App\Http\Controllers\Admin\ContactDetailController as AdminContactDetailController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DonationController as AdminDonationController;
use App\Http\Controllers\Admin\DonationTotalsController as AdminDonationTotalsController;
use App\Http\Controllers\Admin\ErrorLogController as AdminErrorLogController;
use App\Http\Controllers\Admin\LeafApplicationController as AdminLeafApplicationController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\DonationReceiptPreviewController;
use App\Http\Controllers\FormTokenController;
use App\Http\Controllers\LeafApplicationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\AdminOnly;
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

// Signing in and out. Only admins can sign in until donor accounts are built.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// Admin pages. `auth.session` signs out other sessions when an admin's
// password changes.
Route::middleware(['auth', 'auth.session', AdminOnly::class])->prefix('admin')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('admin');

    Route::get('/contacts', [AdminContactController::class, 'index'])->name('admin.contacts');
    Route::get('/contacts/new', [AdminContactController::class, 'create'])->name('admin.contacts.create');
    Route::get('/contacts/review', [AdminContactController::class, 'review'])->name('admin.contacts.review');
    Route::post('/contacts', [AdminContactController::class, 'store'])->name('admin.contacts.store');
    Route::get('/contacts/{contact}', [AdminContactController::class, 'show'])
        ->where('contact', '[0-9]+')
        ->name('admin.contacts.show');
    Route::get('/contacts/{contact}/edit', [AdminContactController::class, 'edit'])
        ->where('contact', '[0-9]+')
        ->name('admin.contacts.edit');
    Route::post('/contacts/{contact}', [AdminContactController::class, 'update'])
        ->where('contact', '[0-9]+')
        ->name('admin.contacts.update');
    Route::post('/contacts/{contact}/merge/{other}', [AdminContactController::class, 'merge'])
        ->whereNumber(['contact', 'other'])
        ->name('admin.contacts.merge');
    // Logging a text, call, visit, note, pickup, or drop-off on a contact's timeline
    Route::get('/contacts/{contact}/activities/new', [AdminContactActivityController::class, 'create'])
        ->whereNumber('contact')
        ->name('admin.contacts.activities.create');
    Route::post('/contacts/{contact}/activities', [AdminContactActivityController::class, 'store'])
        ->whereNumber('contact')
        ->name('admin.contacts.activities.store');
    // A contact's phone numbers, emails, and address; a detail must be the contact's own
    Route::scopeBindings()->whereNumber(['contact', 'detail'])->group(function () {
        Route::post('/contacts/{contact}/details', [AdminContactDetailController::class, 'store'])->name('admin.contacts.details.store');
        Route::post('/contacts/{contact}/details/{detail}/primary', [AdminContactDetailController::class, 'primary'])->name('admin.contacts.details.primary');
        Route::post('/contacts/{contact}/details/{detail}/remove', [AdminContactDetailController::class, 'remove'])->name('admin.contacts.details.remove');
    });

    Route::get('/donations', [AdminDonationController::class, 'index'])->name('admin.donations');
    Route::get('/donations/csv', [AdminDonationController::class, 'download'])->name('admin.donations.csv');
    // The totals are on the dashboard now; earlier bookmarks still work
    Route::redirect('/donations/totals', '/admin');
    Route::get('/donations/totals/csv', [AdminDonationTotalsController::class, 'download'])->name('admin.donations.totals.csv');
    Route::get('/donations/abandoned', [AdminAbandonedCheckoutController::class, 'index'])->name('admin.donations.abandoned');
    Route::get('/donations/abandoned/csv', [AdminAbandonedCheckoutController::class, 'download'])->name('admin.donations.abandoned.csv');
    Route::get('/donations/{donation}', [AdminDonationController::class, 'show'])
        ->where('donation', '[0-9]+|pi_[A-Za-z0-9]+')
        ->name('admin.donations.show');

    Route::get('/leaves', [AdminLeafApplicationController::class, 'index'])->name('admin.leaves');
    Route::get('/leaves/csv', [AdminLeafApplicationController::class, 'download'])->name('admin.leaves.csv');
    Route::get('/leaves/{application}', [AdminLeafApplicationController::class, 'show'])
        ->whereNumber('application')
        ->name('admin.leaves.show');
    Route::post('/leaves/{application}/review', [AdminApplicationContactController::class, 'review'])
        ->whereNumber('application')
        ->name('admin.leaves.review');
    Route::post('/leaves/{application}/contact', [AdminApplicationContactController::class, 'contact'])
        ->whereNumber('application')
        ->name('admin.leaves.contact');

    Route::get('/errors', [AdminErrorLogController::class, 'index'])->name('admin.errors');
    Route::get('/errors/csv', [AdminErrorLogController::class, 'download'])->name('admin.errors.csv');
    Route::post('/errors/close', [AdminErrorLogController::class, 'close'])->name('admin.errors.close');
    Route::post('/errors/reopen', [AdminErrorLogController::class, 'reopen'])->name('admin.errors.reopen');
    // Submission references are six capital letters and digits; lowercase is found too
    Route::get('/errors/{reference}', [AdminErrorLogController::class, 'show'])
        ->where('reference', '[A-Za-z0-9]{6}')
        ->name('admin.errors.show');
});

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
