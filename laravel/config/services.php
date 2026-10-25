<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Stripe, used through the Stripe PHP SDK behind our own payment gateway
    | interface. Local development and staging use test mode keys, and only
    | https://wildland.eco may use live mode (see App\Payments\Stripe\StripeMode).
    | Payments are disabled while the secret key is empty.
    */
    'stripe' => [
        // A secret (sk_) or restricted (rk_) API key
        'secret' => env('STRIPE_SECRET'),

        // The signing secret (whsec_) of this environment's webhook endpoint,
        // or the one printed by the Stripe CLI in local development
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // The single "Donation" product every gift's price is attached to;
        // test and live mode each have their own
        'donation_product_id' => env('STRIPE_DONATION_PRODUCT_ID'),

        // The Customer Portal's no-code login link, where donors manage
        // their recurring gifts
        'portal_login_url' => env('STRIPE_PORTAL_LOGIN_URL'),

        // Pinned, so a Stripe or SDK upgrade never changes the shape of API
        // responses unannounced. Change it deliberately, together with the
        // SDK, after checking Stripe's changelog.
        'api_version' => '2026-09-30.endive',
    ],

];
