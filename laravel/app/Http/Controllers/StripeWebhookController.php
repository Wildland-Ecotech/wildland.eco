<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Payments\Stripe\StripeGateway;
use App\Payments\Stripe\StripeMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Receives Stripe's webhooks (POST /webhooks/stripe). Verifies the
 * signature, stores the event in webhook_events, and processes it on the
 * queue (ProcessWebhookEvent), answering Stripe right away.
 *
 * Errors are returned only when something is actually wrong, such as a
 * missing or invalid signature, an event from the other Stripe mode, or no
 * signing secret configured; Stripe retries those for days. Expected
 * outcomes, including events we don't handle and events already
 * processed, succeed with a short message. See "Webhook Responses" in
 * docs/05-ai-development-notes.md.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = config('services.stripe.webhook_secret');

        if (blank($secret)) {
            Log::error('A Stripe webhook arrived, but STRIPE_WEBHOOK_SECRET is not set.');

            return response('Webhook signing secret is not configured', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        if ((bool) $event->livemode !== StripeMode::expectsLivemode()) {
            Log::error("Refused Stripe event {$event->id} from the wrong mode.", ['livemode' => $event->livemode]);

            return response('Event is from the wrong Stripe mode', 400);
        }

        if ($event->api_version !== config('services.stripe.api_version')) {
            Log::warning("Stripe event {$event->id} uses API version {$event->api_version}, not our pinned " . config('services.stripe.api_version') . '. Update the webhook endpoint\'s version (docs/07-deployment.md#updating-the-stripe-api-version).');
        }

        $stored = WebhookEvent::createOrFirst(
            ['gateway' => StripeGateway::NAME, 'event_id' => $event->id],
            ['type' => $event->type, 'payload' => json_decode($request->getContent(), true)],
        );

        if ($stored->isProcessed()) {
            return response('Already processed');
        }

        ProcessWebhookEvent::dispatch($stored);

        return response('Queued');
    }
}
