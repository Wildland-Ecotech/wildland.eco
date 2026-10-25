<?php

namespace App\Models;

use App\Models\Concerns\BelongsToDesignation;
use App\Models\Concerns\HasGatewayId;
use Database\Factories\CheckoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An attempt to donate, recorded when the donor submits the donate form,
 * including attempts that are never paid. Its reference is a public UUID
 * sent to the gateway as metadata, so the payment can be matched back to
 * it. Deleting a checkout soft-deletes it, so it is hidden but never lost.
 */
#[Fillable([
    'user_id',
    'designation_id',
    'amount_cents',
    'currency',
    'frequency',
    'status',
    'gateway',
    'gateway_session_id',
    'expires_at',
    'completed_at',
    'reminder_sent_at',
])]
class Checkout extends Model
{
    /** @use HasFactory<CheckoutFactory> */
    use BelongsToDesignation, HasFactory, HasGatewayId, HasUuids, SoftDeletes;

    public const GATEWAY_ID_COLUMN = 'gateway_session_id';

    public const FREQUENCY_ONE_TIME = 'one_time';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_ANNUAL = 'annual';

    public const FREQUENCIES = [self::FREQUENCY_ONE_TIME, self::FREQUENCY_MONTHLY, self::FREQUENCY_ANNUAL];

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_STARTED, self::STATUS_COMPLETED, self::STATUS_EXPIRED];

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'usd',
        'status' => self::STATUS_STARTED,
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
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Generate a UUID for the public reference. The primary key stays an
     * auto-incrementing integer.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['reference'];
    }

    /**
     * The donor who started the checkout.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
