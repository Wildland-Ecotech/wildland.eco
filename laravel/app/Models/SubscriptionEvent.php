<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One moment in a recurring donation's history, in our own vocabulary rather
 * than the gateway's. Powers the donor timeline and the churn and retention
 * reports. Append-only: events are never changed or deleted.
 *
 * An event is unique per subscription, type, and source reference, so
 * recording the same event again during a re-import does not duplicate the
 * history. Sync code records events with firstOrCreate() on those columns.
 */
#[Fillable([
    'subscription_id',
    'type',
    'source',
    'source_reference',
    'occurred_at',
    'data',
])]
class SubscriptionEvent extends Model
{
    public const TYPE_CREATED = 'created';

    public const TYPE_RENEWED = 'renewed';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_AMOUNT_CHANGED = 'amount_changed';

    public const TYPE_PAUSED = 'paused';

    public const TYPE_RESUMED = 'resumed';

    public const TYPE_CANCELED = 'canceled';

    public const TYPE_REACTIVATED = 'reactivated';

    public const TYPES = [
        self::TYPE_CREATED,
        self::TYPE_RENEWED,
        self::TYPE_PAYMENT_FAILED,
        self::TYPE_AMOUNT_CHANGED,
        self::TYPE_PAUSED,
        self::TYPE_RESUMED,
        self::TYPE_CANCELED,
        self::TYPE_REACTIVATED,
    ];

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_DONOR = 'donor';

    public const SOURCES = [self::SOURCE_WEBHOOK, self::SOURCE_IMPORT, self::SOURCE_ADMIN, self::SOURCE_DONOR];

    /**
     * Refuse to change or delete recorded history.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Subscription events are append-only and cannot be changed.');
        });

        static::deleting(function () {
            throw new LogicException('Subscription events are append-only and cannot be deleted.');
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
            'occurred_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /**
     * The recurring donation this event belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
