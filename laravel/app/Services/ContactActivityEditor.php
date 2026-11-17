<?php

namespace App\Services;

use App\Models\ContactActivity;
use App\Models\Edit;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Editing and deleting timeline entries as an admin, recording each change
 * in the edits table. Applications follow their pickups and drop-offs:
 * applications newly linked to a pickup or drop-off are marked complete,
 * and applications left with no pickup or drop-off go back to pending, so
 * nothing slips off the follow-up list unnoticed. Application status
 * changes are recorded as edits of the application, by the same admin.
 */
class ContactActivityEditor
{
    /**
     * Save new values for an entry as one edit, Assigned when a drop-off
     * with no contact gets one. Returns each changed field's stored value
     * before and after, empty when nothing changed.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function update(ContactActivity $activity, array $values, User $admin): array
    {
        return DB::transaction(function () use ($activity, $values, $admin) {
            $assigning = $activity->isUnknown() && ($values['contact_id'] ?? null) !== null;

            // Who identified the donor; cleared when the bags go back to Unknown
            if ($assigning) {
                $values += ['assigned_at' => now(), 'assigned_by' => $admin->id];
            } elseif (array_key_exists('contact_id', $values) && $values['contact_id'] === null) {
                $values += ['assigned_at' => null, 'assigned_by' => null];
            }

            $before = $this->completedApplication($activity);

            $activity->forceFill($values);
            $after = $this->completedApplication($activity);
            // Read before saving, since saving marks it complete (ContactActivity::booted())
            $completing = $after !== null && $after !== $before
                ? LeafApplication::withTrashed()->find($after)
                : null;
            $wasComplete = $completing?->status === LeafApplication::STATUS_COMPLETE;

            $fields = $activity->saveRecordingEdit($admin, $assigning ? Edit::ACTION_ASSIGNED : Edit::ACTION_EDITED);

            if ($completing !== null && ! $wasComplete) {
                $completing->recordEdit(Edit::ACTION_EDITED, ['status' => ['from' => $completing->status, 'to' => LeafApplication::STATUS_COMPLETE]], $admin);
            }

            if ($before !== null && $before !== $after) {
                $this->reopenIfUncovered($before, $admin);
            }

            return $fields;
        });
    }

    /**
     * Soft-delete an entry, recording the deletion.
     */
    public function delete(ContactActivity $activity, User $admin): void
    {
        DB::transaction(function () use ($activity, $admin) {
            $before = $this->completedApplication($activity);

            $activity->delete();
            $activity->recordEdit(Edit::ACTION_DELETED, [], $admin);

            if ($before !== null) {
                $this->reopenIfUncovered($before, $admin);
            }
        });
    }

    /**
     * The application a pickup or drop-off completes, or null.
     */
    private function completedApplication(ContactActivity $activity): ?int
    {
        return $activity->completesApplication() ? (int) $activity->leaf_application_id : null;
    }

    /**
     * Put a complete application back to pending when no other pickup or
     * drop-off, not deleted, is for it.
     */
    private function reopenIfUncovered(int $applicationId, User $admin): void
    {
        $covered = ContactActivity::query()
            ->where('leaf_application_id', $applicationId)
            ->whereIn('type', ContactActivity::COMPLETING_TYPES)
            ->exists();

        $application = LeafApplication::withTrashed()->find($applicationId);

        if (! $covered && $application?->status === LeafApplication::STATUS_COMPLETE) {
            $application->status = LeafApplication::STATUS_PENDING;
            $application->saveRecordingEdit($admin);
        }
    }
}
