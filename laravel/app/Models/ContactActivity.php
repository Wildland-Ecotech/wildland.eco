<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One entry on a contact's timeline, logged by an admin: a text or call,
 * outgoing or incoming, a visit, a note, or a pickup or drop-off of leaves.
 * Applications and form errors appear on the timeline from their own tables,
 * so they aren't copied here.
 *
 * A pickup or drop-off for an application marks it complete, so it leaves
 * the map and the follow-up list. Holds conversations with people: never
 * paste them into issues, chat, or the repository. Deleting an entry
 * soft-deletes it.
 */
#[Fillable([
    'type',
    'direction',
    'happened_at',
    'body',
    'bag_count',
    'leaf_application_id',
])]
class ContactActivity extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_CALL = 'call';

    public const TYPE_VISIT = 'visit';

    public const TYPE_NOTE = 'note';

    public const TYPE_PICKUP = 'pickup';

    public const TYPE_DROP_OFF = 'drop_off';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_CALL, self::TYPE_VISIT, self::TYPE_NOTE, self::TYPE_PICKUP, self::TYPE_DROP_OFF];

    /**
     * Entries with a direction: who texted or called whom.
     */
    public const MESSAGE_TYPES = [self::TYPE_TEXT, self::TYPE_CALL];

    /**
     * Entries where we and the contact were in touch, for the follow-up
     * list. Notes are only for us.
     */
    public const CONVERSATION_TYPES = [self::TYPE_TEXT, self::TYPE_CALL, self::TYPE_VISIT];

    /**
     * Entries that mark their application complete.
     */
    public const COMPLETING_TYPES = [self::TYPE_PICKUP, self::TYPE_DROP_OFF];

    public const DIRECTION_OUTGOING = 'outgoing';

    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTIONS = [self::DIRECTION_OUTGOING, self::DIRECTION_INCOMING];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'bag_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (ContactActivity $activity) {
            if ($activity->completesApplication() && ($activity->wasRecentlyCreated || $activity->wasChanged(['type', 'leaf_application_id']))) {
                LeafApplication::withTrashed()
                    ->whereKey($activity->leaf_application_id)
                    ->update(['status' => LeafApplication::STATUS_COMPLETE]);
            }
        });
    }

    /**
     * Whether this is a pickup or drop-off for an application.
     */
    public function completesApplication(): bool
    {
        return in_array($this->type, self::COMPLETING_TYPES, true) && $this->leaf_application_id !== null;
    }

    /**
     * Whether we reached the contact: a text or call from us, or a visit.
     */
    public function reachedThem(): bool
    {
        return $this->type === self::TYPE_VISIT
            || (in_array($this->type, self::MESSAGE_TYPES, true) && $this->direction === self::DIRECTION_OUTGOING);
    }

    /**
     * The contact whose timeline it's on, even if deleted.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /**
     * The application a pickup or drop-off was for, even if deleted, or
     * null.
     *
     * @return BelongsTo<LeafApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeafApplication::class, 'leaf_application_id')->withTrashed();
    }

    /**
     * The admin who logged it, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
