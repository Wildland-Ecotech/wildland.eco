<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change to a record by an admin, for the audit trail: edits,
 * deletions, assignments, splits, and application withdrawals and
 * reopenings, with each changed field's stored
 * value before and after (`fields`). Append-only: rows are never changed or deleted, so a
 * later edit never hides who made an earlier one. Records gain a history
 * with the RecordsEdits trait; their type is stored as a short name from
 * the morph map in AppServiceProvider.
 */
class Edit extends Model
{
    /**
     * Rows are never updated, so there's only a created_at.
     */
    public const UPDATED_AT = null;

    public const ACTION_EDITED = 'edited';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_ASSIGNED = 'assigned';

    public const ACTION_SPLIT = 'split';

    public const ACTION_WITHDRAWN = 'withdrawn';

    public const ACTION_REOPENED = 'reopened';

    public const ACTIONS = [
        self::ACTION_EDITED,
        self::ACTION_DELETED,
        self::ACTION_ASSIGNED,
        self::ACTION_SPLIT,
        self::ACTION_WITHDRAWN,
        self::ACTION_REOPENED,
    ];

    /**
     * How each action reads in a history, such as "Edited".
     */
    public const ACTION_LABELS = [
        self::ACTION_EDITED => 'Edited',
        self::ACTION_DELETED => 'Deleted',
        self::ACTION_ASSIGNED => 'Assigned',
        self::ACTION_SPLIT => 'Split',
        self::ACTION_WITHDRAWN => 'Withdrawn',
        self::ACTION_REOPENED => 'Reopened',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The record changed, even if deleted.
     *
     * @return MorphTo<Model, $this>
     */
    public function editable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * The admin who made the change, even if deleted, or null.
     *
     * @return BelongsTo<User, $this>
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by')->withTrashed();
    }
}
