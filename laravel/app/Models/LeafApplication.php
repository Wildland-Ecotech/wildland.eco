<?php

namespace App\Models;

use App\Reports\FormSubmissionReport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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
 *
 * An admin marks an application that isn't a real request as a test or
 * spam with flag(), which soft-deletes it and closes its form submissions
 * the same way, and unmarks one marked by mistake with unflag(). The flag
 * columns are set only that way, never from form input.
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
     * How an admin marked an application that isn't a real request: someone
     * testing the form, or spam. The values match FormSubmission's
     * resolutions, so marking an application closes its form submissions
     * the same way.
     */
    public const FLAG_TEST = FormSubmission::RESOLUTION_TEST;

    public const FLAG_SPAM = FormSubmission::RESOLUTION_SPAM;

    public const FLAGS = [self::FLAG_TEST, self::FLAG_SPAM];

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
            'flagged_at' => 'datetime',
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
     * The admin who marked the application as a test or spam, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by')->withTrashed();
    }

    /**
     * Whether an admin marked the application as a test or spam.
     */
    public function isFlagged(): bool
    {
        return $this->flagged_as !== null;
    }

    /**
     * Mark the application as a test or spam (FLAGS), with an optional note,
     * recording which admin did and when. It's soft-deleted, so it leaves
     * every list, the map, and the follow-up list, and its contact can be
     * deleted once all their applications are marked. The form submission
     * that saved it, and the visitor's earlier tries that Error Logs links
     * to it, are closed the same way (FormSubmissionReport::forApplication()),
     * unless an admin already closed them.
     */
    public function flag(User $admin, string $flag, ?string $note = null): void
    {
        DB::transaction(function () use ($admin, $flag, $note) {
            $this->forceFill([
                'flagged_as' => $flag,
                'flagged_at' => now(),
                'flagged_by' => $admin->id,
                'flag_note' => $note,
            ])->save();

            $this->delete();

            $closing = "Application {$this->id} was marked as " . ($flag === self::FLAG_TEST ? 'a test' : 'spam') . '.';

            app(FormSubmissionReport::class)->forApplication($this)
                ->reject(fn (FormSubmission $submission) => $submission->isClosed())
                ->each(fn (FormSubmission $submission) => $submission->close($admin, $flag, $closing));
        });
    }

    /**
     * Unmark an application marked by mistake, restoring it, and its
     * contact when that was deleted without being merged, such as after
     * deleting a contact whose applications were all marked. Form
     * submissions closed with it stay closed; each can be reopened on Error
     * Logs.
     */
    public function unflag(): void
    {
        DB::transaction(function () {
            $this->forceFill([
                'flagged_as' => null,
                'flagged_at' => null,
                'flagged_by' => null,
                'flag_note' => null,
            ])->save();

            $this->restore();

            $contact = $this->contact;

            if ($contact?->trashed() && $contact->merged_into_id === null) {
                $contact->restore();
            }
        });
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
     * Applications not marked as a test or spam. Deleted ones are included
     * only when the query includes them, such as with withTrashed().
     *
     * @param  Builder<LeafApplication>  $query
     */
    #[Scope]
    protected function notFlagged(Builder $query): void
    {
        $query->whereNull('flagged_as');
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
