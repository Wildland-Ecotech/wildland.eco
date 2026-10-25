<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tax receipt sent to a donor: for a single donation, or a year-end summary
 * of their giving. The receipt number format and email are built with
 * receipts (milestone 7). Deleting a receipt soft-deletes it, so it is
 * hidden but never lost, and its number is never reused.
 */
#[Fillable([
    'user_id',
    'donation_id',
    'receipt_number',
    'type',
    'tax_year',
    'amount_cents',
    'sent_at',
])]
class DonationReceipt extends Model
{
    use SoftDeletes;

    public const TYPE_SINGLE = 'single';

    public const TYPE_ANNUAL_SUMMARY = 'annual_summary';

    public const TYPES = [self::TYPE_SINGLE, self::TYPE_ANNUAL_SUMMARY];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'amount_cents' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The donor the receipt was issued to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The donation this receipt is for, when it covers a single donation.
     *
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
