<?php

namespace App\Models;

use App\Models\Concerns\BelongsToDesignation;
use App\Models\Concerns\HasGatewayId;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recurring gift, synced from the payment gateway. Each payment it
 * collects is a donation, and its history is recorded as subscription
 * events. Deleting a subscription soft-deletes it, so it is hidden but
 * never lost.
 */
#[Fillable([
    'user_id',
    'designation_id',
    'gateway',
    'gateway_subscription_id',
    'amount_cents',
    'currency',
    'interval',
    'status',
    'started_at',
    'current_period_end',
    'cancel_at',
    'canceled_at',
    'gateway_data',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToDesignation, HasFactory, HasGatewayId, SoftDeletes;

    public const GATEWAY_ID_COLUMN = 'gateway_subscription_id';

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public const INTERVALS = [self::INTERVAL_MONTH, self::INTERVAL_YEAR];

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_INCOMPLETE,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_UNPAID,
        self::STATUS_PAUSED,
        self::STATUS_CANCELED,
    ];

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'usd',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'started_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'gateway_data' => 'array',
        ];
    }

    /**
     * The donor giving the recurring gift.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The payments the recurring gift has collected, including failed ones.
     *
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * The recurring gift's history, oldest first.
     *
     * @return HasMany<SubscriptionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->oldest('occurred_at');
    }
}
