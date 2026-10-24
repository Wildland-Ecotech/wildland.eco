<?php

namespace App\Models;

use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a donor directed their gift toward. Every gift goes to the General
 * Fund, created by the designations migration, unless it names another
 * designation. Gifts to restricted designations can only be spent on them.
 * The slug travels in gateway metadata. Deleting a designation soft-deletes
 * it, so it is hidden but never lost.
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'is_restricted',
    'is_active',
    'sort_order',
])]
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use HasFactory, SoftDeletes;

    public const GENERAL_FUND_SLUG = 'general-fund';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_restricted' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The General Fund, which every gift goes to unless it names another
     * designation.
     */
    public static function generalFund(): self
    {
        return static::where('slug', self::GENERAL_FUND_SLUG)->firstOrFail();
    }

    /**
     * Donation attempts directed to this designation.
     *
     * @return HasMany<Checkout, $this>
     */
    public function checkouts(): HasMany
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * Recurring gifts directed to this designation.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Payments directed to this designation, including failed ones.
     *
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }
}
