<?php

namespace App\Models\Traits;

use App\Models\Edit;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A history of changes for a model, in the append-only edits table: who
 * changed what and when. The model needs a short type name in the morph map
 * (AppServiceProvider). For Eloquent models.
 */
trait RecordsEdits
{
    /**
     * Fields left out of the history: timestamps change on every save.
     */
    public const UNRECORDED_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Changes to the record, newest first.
     *
     * @return MorphMany<Edit, $this>
     */
    public function edits(): MorphMany
    {
        return $this->morphMany(Edit::class, 'editable')->latest('created_at')->latest('id');
    }

    /**
     * Record a change, with each field's stored value before and after.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $fields
     */
    public function recordEdit(string $action, array $fields, ?User $admin): Edit
    {
        $edit = new Edit;
        $edit->forceFill([
            'editable_type' => $this->getMorphClass(),
            'editable_id' => $this->getKey(),
            'action' => $action,
            'fields' => $fields,
            'edited_by' => $admin?->id,
        ])->save();

        return $edit;
    }

    /**
     * The unsaved changes, each field's stored value before and after,
     * leaving out timestamps.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function pendingChanges(): array
    {
        $changes = [];

        foreach ($this->getDirty() as $field => $value) {
            if (! in_array($field, self::UNRECORDED_FIELDS, true)) {
                $changes[$field] = ['from' => $this->getRawOriginal($field), 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * Save the record and record the changes as one edit by the admin, if
     * anything changed. Returns the changes.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function saveRecordingEdit(?User $admin, string $action = Edit::ACTION_EDITED): array
    {
        $changes = $this->pendingChanges();
        $this->save();

        if ($changes !== []) {
            $this->recordEdit($action, $changes, $admin);
        }

        return $changes;
    }
}
