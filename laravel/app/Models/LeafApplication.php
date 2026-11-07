<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 *
 * An application is pending until a pickup or drop-off is logged for it on
 * the contact's timeline (ContactActivity), which marks it complete. Its
 * `status` and `follow_up_on` are set only that way and by admin pages.
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
     * Waiting for its pickup or drop-off, or done.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_COMPLETE];

    /**
     * Why an application's contact needs an admin to look: the contact it
     * was linked to has a different current address; its email or phone
     * number is also on another contact, such as two people sharing a phone
     * number, or the same person with a differently written name; or it
     * matches more than one contact with the same name, so it isn't linked
     * to either.
     */
    public const REVIEW_ADDRESS = 'address';

    public const REVIEW_SHARED_EMAIL = 'shared_email';

    public const REVIEW_SHARED_PHONE = 'shared_phone';

    public const REVIEW_CONFLICT = 'conflict';

    public const REVIEW_REASONS = [self::REVIEW_ADDRESS, self::REVIEW_SHARED_EMAIL, self::REVIEW_SHARED_PHONE, self::REVIEW_CONFLICT];

    /**
     * How each review reason is described to admins.
     */
    public const REVIEW_LABELS = [
        self::REVIEW_ADDRESS => 'Different address',
        self::REVIEW_SHARED_EMAIL => 'Email also on another contact',
        self::REVIEW_SHARED_PHONE => 'Phone number also on another contact',
        self::REVIEW_CONFLICT => 'Matches more than one contact with this name',
    ];

    /**
     * The model's default values, matching the database's.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

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
            'follow_up_on' => 'date',
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
     * The timeline entries logged for this application, such as its pickup.
     *
     * @return HasMany<ContactActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ContactActivity::class);
    }

    /**
     * The admin who reviewed the application's contact, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function contactReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_reviewed_by');
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

    /**
     * Applications still waiting for their pickup or drop-off.
     *
     * @param  Builder<LeafApplication>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }
}
