<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to donate leaves to the leaf program, by drop-off or pickup.
 * Holds the donor's contact details, which are never shared.
 */
#[Fillable([
    'type',
    'name',
    'email',
    'phone',
    'address',
    'estimated_bag_count',
    'ready_now',
    'ready_date',
    'referral_source',
    'questions',
    'terms_accepted_at',
])]
class LeafApplication extends Model
{
    public const TYPE_DROP_OFF = 'drop_off';

    public const TYPE_PICKUP = 'pickup';

    public const TYPES = [self::TYPE_DROP_OFF, self::TYPE_PICKUP];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_bag_count' => 'integer',
            'ready_now' => 'boolean',
            'ready_date' => 'date',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /**
     * Store email addresses lowercase, so finding a donor's applications by
     * email is case-insensitive.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }
}
