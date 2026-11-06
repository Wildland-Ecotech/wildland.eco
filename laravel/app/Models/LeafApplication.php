<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request to donate leaves to the leaf program, by drop-off or pickup.
 * Holds the donor's contact details, which are never shared. Deleting an
 * application soft-deletes it, so it is hidden but never lost.
 *
 * Each application belongs to a contact, linked by App\Services\ContactLinker
 * after it's saved. The application keeps its details as submitted. When
 * linking needs an admin to look, `contact_review` lists why; the contact
 * columns are set only by ContactLinker and admin pages, never from form
 * input.
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
    use SoftDeletes;

    public const TYPE_DROP_OFF = 'drop_off';

    public const TYPE_PICKUP = 'pickup';

    public const TYPES = [self::TYPE_DROP_OFF, self::TYPE_PICKUP];

    /**
     * Why linking an application to its contact needs an admin to look: the
     * contact it matched has a different name, or a different current
     * address, or its email and phone number match different contacts, so it
     * isn't linked to either.
     */
    public const REVIEW_NAME = 'name';

    public const REVIEW_ADDRESS = 'address';

    public const REVIEW_CONFLICT = 'conflict';

    public const REVIEW_REASONS = [self::REVIEW_NAME, self::REVIEW_ADDRESS, self::REVIEW_CONFLICT];

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
            'contact_review' => 'array',
            'contact_reviewed_at' => 'datetime',
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

    /**
     * The person who applied, even if their contact was deleted. Null until
     * linked, or while waiting for review.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /**
     * Whether linking the application to its contact still needs an admin
     * to look.
     */
    public function needsContactReview(): bool
    {
        return $this->contact_review !== null && $this->contact_reviewed_at === null;
    }

    /**
     * Applications whose linking still needs an admin to look.
     *
     * @param  Builder<LeafApplication>  $query
     */
    #[Scope]
    protected function needingContactReview(Builder $query): void
    {
        $query->whereNotNull('contact_review')->whereNull('contact_reviewed_at');
    }
}
