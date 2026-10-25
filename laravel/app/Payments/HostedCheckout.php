<?php

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * A checkout started at a payment gateway: where to send the donor to pay,
 * and when the offer expires if they don't.
 */
final readonly class HostedCheckout
{
    public function __construct(
        // The gateway's ID for the session, stored in checkouts.gateway_session_id
        public string $id,
        // The gateway's payment page
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
