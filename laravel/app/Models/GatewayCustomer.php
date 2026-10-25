<?php

namespace App\Models;

use App\Models\Concerns\HasGatewayId;
use Database\Factories\GatewayCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's customer record at a payment gateway. A donor may have several,
 * such as more than one Stripe customer for the same email address.
 * Deleting one soft-deletes it, so it is hidden but never lost.
 */
#[Fillable([
    'user_id',
    'gateway',
    'gateway_customer_id',
])]
class GatewayCustomer extends Model
{
    /** @use HasFactory<GatewayCustomerFactory> */
    use HasFactory, HasGatewayId, SoftDeletes;

    public const GATEWAY_ID_COLUMN = 'gateway_customer_id';

    /**
     * The donor this customer record belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
