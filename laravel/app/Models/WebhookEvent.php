<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An incoming payment gateway webhook, stored before it is processed on the
 * queue. Used for deduplication, debugging, and replay: an event is stored
 * once per gateway and event ID, and a failed event keeps its error so it
 * can be retried. Only the processing status changes after an event is
 * stored. Append-only: events are never deleted.
 */
#[Fillable([
    'gateway',
    'event_id',
    'type',
    'payload',
    'processed_at',
    'attempts',
    'error',
])]
class WebhookEvent extends Model
{
    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * Refuse to delete the log.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Webhook events are append-only and cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Whether the event has been processed successfully.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
