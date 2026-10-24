<?php

namespace App\Models\Concerns;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For checkouts, subscriptions, and donations: what the donor directed their
 * gift toward. A record created without a designation goes to the General
 * Fund, including payments imported from outside our donation flow.
 */
trait BelongsToDesignation
{
    /**
     * Assign the General Fund to new records without a designation.
     */
    public static function bootBelongsToDesignation(): void
    {
        static::creating(function ($model) {
            $model->designation_id ??= Designation::generalFund()->id;
        });
    }

    /**
     * The designation the gift supports.
     *
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
