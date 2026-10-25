<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Payments\Stripe\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a stored payment gateway webhook event on the queue, after the
 * webhook has already been answered. Failures are retried with increasing
 * delays; each attempt is counted on the event, and the last error is kept
 * on it until it succeeds. A job that runs out of tries stays in
 * failed_jobs for `php artisan queue:retry`, and the nightly sync catches
 * up on whatever it missed.
 */
class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job fails for good, about a day in all.
     */
    public int $tries = 6;

    public function __construct(public WebhookEvent $event) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 43200];
    }

    /**
     * One job per event at a time, so a redelivered event can't be
     * processed alongside its own retry.
     */
    public function uniqueId(): string
    {
        return (string) $this->event->id;
    }

    public function handle(StripeEventProcessor $processor): void
    {
        if ($this->event->isProcessed()) {
            return;
        }

        $this->event->increment('attempts');

        try {
            $processor->process($this->event);
        } catch (Throwable $exception) {
            $this->event->update(['error' => mb_substr($exception->getMessage(), 0, 2000)]);

            throw $exception;
        }

        $this->event->update(['processed_at' => now(), 'error' => null]);
    }
}
