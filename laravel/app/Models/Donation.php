<?php

namespace App\Models;

use App\Models\Concerns\BelongsToDesignation;
use App\Models\Concerns\HasGatewayId;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payment, one-time or from a recurring gift, synced from the payment
 * gateway. Failed payments are recorded too. A payment we actually received
 * stays received after a refund, since refunds are kept for accounting.
 * Deleting a donation soft-deletes it, so it is hidden but never lost.
 */
#[Fillable([
    'user_id',
    'designation_id',
    'subscription_id',
    'checkout_id',
    'gateway',
    'gateway_payment_id',
    'gateway_invoice_id',
    'amount_cents',
    'fee_cents',
    'refunded_cents',
    'currency',
    'status',
    'paid_at',
    'gateway_data',
])]
class Donation extends Model
{
    /** @use HasFactory<DonationFactory> */
    use BelongsToDesignation, HasFactory, HasGatewayId, SoftDeletes;

    public const GATEWAY_ID_COLUMN = 'gateway_payment_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_PARTIALLY_REFUNDED,
    ];

    /**
     * Payments we actually received, including ones refunded later. A donor
     * with any of these has transaction history and cannot be deleted.
     */
    public const RECEIVED_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_REFUNDED,
        self::STATUS_PARTIALLY_REFUNDED,
    ];

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'usd',
        'refunded_cents' => 0,
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
            'fee_cents' => 'integer',
            'refunded_cents' => 'integer',
            'paid_at' => 'datetime',
            'gateway_data' => 'array',
        ];
    }

    /**
     * Limit the query to payments we actually received.
     *
     * @param  Builder<Donation>  $query
     */
    #[Scope]
    protected function received(Builder $query): void
    {
        $query->whereIn('status', self::RECEIVED_STATUSES);
    }

    /**
     * Whether we actually received the payment, even if it was refunded later.
     */
    public function wasReceived(): bool
    {
        return in_array($this->status, self::RECEIVED_STATUSES, true);
    }

    /**
     * The donor who made the payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The recurring gift that collected the payment, if any.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The checkout on our donate page that started the payment, if any.
     *
     * @return BelongsTo<Checkout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * The receipt sent for this payment, if any.
     *
     * @return HasOne<DonationReceipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(DonationReceipt::class);
    }
}
