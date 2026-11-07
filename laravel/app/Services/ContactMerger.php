<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Merges one contact into another, when an admin finds they're the same
 * person, such as a name written two ways. Everything moves to the contact
 * kept, nothing is lost, and the merged contact is deleted, pointing to the
 * one it became part of.
 */
class ContactMerger
{
    /**
     * Merge $from into $into:
     *
     * - Every application of $from, deleted ones too, moves to $into, and
     *   so does every timeline entry.
     * - Each detail of $from moves to $into, unless $into has it already,
     *   in which case $into's is kept, and put back in use if only $from's
     *   was current. A moved address or primary detail stays current or
     *   primary only when $into has none; otherwise it's kept as a previous
     *   address, or as a phone number or email that isn't the main one.
     * - $from's notes are added after $into's.
     * - $from is deleted, with `merged_into_id` pointing to $into.
     * - Reviews of $into's applications flagged only because their email or
     *   phone number was on another contact are marked done by $admin when
     *   no other contact has it anymore.
     *
     * Returns how many applications moved.
     */
    public function merge(Contact $from, Contact $into, User $admin): int
    {
        return DB::transaction(function () use ($from, $into, $admin) {
            $moved = LeafApplication::withTrashed()->where('contact_id', $from->id)->update(['contact_id' => $into->id]);
            ContactActivity::withTrashed()->where('contact_id', $from->id)->update(['contact_id' => $into->id]);

            foreach ($from->details()->orderByRaw('removed_at IS NOT NULL')->orderByDesc('is_primary')->orderBy('id')->get() as $detail) {
                $this->moveDetail($detail, $into);
            }

            if (trim((string) $from->notes) !== '') {
                $into->notes = trim((string) $into->notes) === '' ? $from->notes : trim($into->notes) . "\n\n" . $from->notes;
                $into->save();
            }

            $from->forceFill(['merged_into_id' => $into->id])->save();
            $from->delete();

            $this->settleReviews($into, $admin);

            return $moved;
        });
    }

    /**
     * Move one of the merged contact's details to $into, or keep $into's
     * own when it has the same one.
     */
    private function moveDetail(ContactDetail $detail, Contact $into): void
    {
        $existing = $into->findDetail($detail->type, $detail->value);

        if ($existing !== null) {
            // Only the merged contact's was in use: put $into's back in use,
            // an address only when $into has no other
            if ($existing->isRemoved() && ! $detail->isRemoved()) {
                if ($detail->type !== ContactDetail::TYPE_ADDRESS) {
                    $into->addDetail($existing->type, $existing->value);
                } elseif ($into->address() === null) {
                    $into->moveTo($existing->value);
                }
            }

            return;
        }

        $changes = ['contact_id' => $into->id];

        if (! $detail->isRemoved()) {
            if ($detail->type === ContactDetail::TYPE_ADDRESS && $into->address() !== null) {
                $changes += ['is_primary' => false, 'removed_at' => now()];
            } elseif ($detail->is_primary && $detail->type !== ContactDetail::TYPE_ADDRESS && $into->primary($detail->type) !== null) {
                $changes['is_primary'] = false;
            }
        }

        $detail->forceFill($changes)->save();
    }

    /**
     * Mark done the reviews of $into's applications that were flagged only
     * for sharing an email or phone number, once no other contact has it.
     */
    private function settleReviews(Contact $into, User $admin): void
    {
        $shared = [LeafApplication::REVIEW_SHARED_EMAIL, LeafApplication::REVIEW_SHARED_PHONE];

        LeafApplication::needingContactReview()
            ->where('contact_id', $into->id)
            ->get()
            ->filter(fn (LeafApplication $application) => array_diff($application->contact_review, $shared) === [])
            ->reject(fn (LeafApplication $application) => $this->stillShared($into, $application))
            ->each(fn (LeafApplication $application) => $application->forceFill([
                'contact_reviewed_at' => now(),
                'contact_reviewed_by' => $admin->id,
            ])->save());
    }

    /**
     * Whether the application's email or phone number is still on a contact
     * other than $into, not deleted, current or removed.
     */
    private function stillShared(Contact $into, LeafApplication $application): bool
    {
        $values = array_filter([
            ContactDetail::TYPE_EMAIL => ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $application->email),
            ContactDetail::TYPE_PHONE => ContactDetail::normalize(ContactDetail::TYPE_PHONE, $application->phone),
        ]);

        return $values !== [] && ContactDetail::query()
            ->where(function ($query) use ($values) {
                foreach ($values as $type => $normalized) {
                    $query->orWhere(fn ($query) => $query->where('type', $type)->where('normalized', $normalized));
                }
            })
            ->where('contact_id', '!=', $into->id)
            ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
            ->exists();
    }
}
