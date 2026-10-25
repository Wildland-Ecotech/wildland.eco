<?php

namespace App\Payments\Stripe\Concerns;

use App\Payments\Stripe\StripeMode;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * For classes that call the Stripe API: the client, resolved when first
 * needed; a wrapper that keeps Stripe notices from failing calls; and the
 * check that objects come from the mode this site must use.
 */
trait CallsStripe
{
    /**
     * The Stripe client, resolved when first needed, so the class can be
     * created without a key.
     */
    protected function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }

    /**
     * Make a Stripe API call, logging any notice Stripe attaches to the
     * response instead of letting it fail the call.
     *
     * The SDK raises notices as PHP warnings, which Laravel throws as
     * exceptions after Stripe has already done the work, such as creating a
     * session we would then never learn the ID of. The containers set
     * STRIPE_SUPPRESS_NOTICES so the SDK skips them; this is the backstop.
     *
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    protected function call(callable $request): mixed
    {
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if ($level === E_USER_WARNING && str_contains($file, DIRECTORY_SEPARATOR . 'stripe-php' . DIRECTORY_SEPARATOR)) {
                Log::warning('Stripe notice: ' . $message);

                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });

        try {
            return $request();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Refuse a Stripe object or event from the other mode, so test and live
     * data never mix. Keys are already checked for their mode, so this only
     * fails if something is badly misconfigured.
     *
     * Deleted objects, such as a deleted customer ({id, object, deleted}),
     * carry no livemode and are accepted: only their ID is used.
     *
     * @throws RuntimeException
     */
    protected function assertLivemode(StripeObject $object): void
    {
        if (! isset($object['livemode'])) {
            return;
        }

        if ((bool) $object['livemode'] !== StripeMode::expectsLivemode()) {
            throw new RuntimeException(sprintf(
                'Refused %s %s from Stripe\'s %s mode; this site uses %s mode.',
                $object['object'],
                $object['id'],
                $object['livemode'] ? 'live' : 'test',
                StripeMode::expected(),
            ));
        }
    }
}
