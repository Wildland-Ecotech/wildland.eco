<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class WebhookEventTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $eventId = 'evt_example'): WebhookEvent
    {
        return WebhookEvent::create([
            'gateway' => 'stripe',
            'event_id' => $eventId,
            'type' => 'checkout.session.completed',
            'payload' => ['id' => $eventId, 'data' => ['object' => ['id' => 'cs_test_example']]],
        ]);
    }

    public function test_a_stored_event_waits_to_be_processed(): void
    {
        $event = $this->store()->refresh();

        $this->assertFalse($event->isProcessed());
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->error);
        $this->assertSame('cs_test_example', $event->payload['data']['object']['id']);
    }

    public function test_the_processing_status_can_be_updated(): void
    {
        $event = $this->store();

        $event->update(['attempts' => 1, 'processed_at' => now()]);

        $this->assertTrue($event->fresh()->isProcessed());
    }

    public function test_the_log_cannot_be_deleted(): void
    {
        $event = $this->store();

        $this->assertThrows(fn () => $event->delete(), LogicException::class);

        $this->assertModelExists($event);
    }

    public function test_a_redelivered_event_is_stored_only_once(): void
    {
        $this->store('evt_duplicate');

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        $this->store('evt_duplicate');
    }
}
