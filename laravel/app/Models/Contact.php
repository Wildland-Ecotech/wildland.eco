<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A person we keep in touch with, starting with the people who apply to
 * donate leaves. Only a name is required. Their emails, phone numbers, and
 * address are ContactDetails, several per contact, kept after they change.
 *
 * Holds personal details: never paste them into issues, chat, or the
 * repository. Deleting a contact soft-deletes it. A contact merged into
 * another (App\Services\ContactMerger) is deleted, with `merged_into_id`
 * pointing to the one it became part of.
 */
#[Fillable([
    'name',
    'notes',
])]
class Contact extends Model
{
    use SoftDeletes;

    /**
     * Every email, phone number, and address, including removed ones.
     *
     * @return HasMany<ContactDetail, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(ContactDetail::class);
    }

    /**
     * The contact this one was merged into, even if that one was deleted
     * too, or null.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'merged_into_id')->withTrashed();
    }

    /**
     * The contact's leaf applications.
     *
     * @return HasMany<LeafApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(LeafApplication::class);
    }

    /**
     * The primary email or phone number, or null.
     */
    public function primary(string $type): ?ContactDetail
    {
        return $this->details()->current()->where('type', $type)->where('is_primary', true)->first();
    }

    /**
     * The current address, or null.
     */
    public function address(): ?ContactDetail
    {
        return $this->details()->current()->where('type', ContactDetail::TYPE_ADDRESS)->first();
    }

    /**
     * Make an email or phone number the primary one of its type: the
     * contact's existing detail with the same normalized value, put back in
     * use if it was removed, or a new one. Other details of the type stay,
     * no longer primary.
     */
    public function usePrimary(string $type, string $value): ContactDetail
    {
        return DB::transaction(function () use ($type, $value) {
            $detail = $this->findDetail($type, $value) ?? $this->details()->make(['type' => $type, 'value' => $value]);

            // Before the new primary is saved, since the database allows
            // only one current primary per type
            $this->details()->current()->where('type', $type)->where('is_primary', true)
                ->when($detail->exists, fn ($query) => $query->whereKeyNot($detail->getKey()))
                ->update(['is_primary' => false]);

            $detail->forceFill(['is_primary' => true, 'removed_at' => null])->save();

            return $detail;
        });
    }

    /**
     * Make this the contact's current address. A different current address
     * is marked removed, and kept for matching.
     */
    public function moveTo(string $value): ContactDetail
    {
        return DB::transaction(function () use ($value) {
            $detail = $this->findDetail(ContactDetail::TYPE_ADDRESS, $value)
                ?? $this->details()->make(['type' => ContactDetail::TYPE_ADDRESS, 'value' => $value]);

            // Before the new address is saved, since the database allows
            // only one current address
            $this->details()->current()->where('type', ContactDetail::TYPE_ADDRESS)
                ->when($detail->exists, fn ($query) => $query->whereKeyNot($detail->getKey()))
                ->update(['is_primary' => false, 'removed_at' => now()]);

            $detail->forceFill(['is_primary' => true, 'removed_at' => null])->save();

            return $detail;
        });
    }

    /**
     * Add an email or phone number, or put a matching one back in use. It
     * becomes primary only when the contact has no primary one of the type.
     * An address becomes the current address (see moveTo()).
     */
    public function addDetail(string $type, string $value): ContactDetail
    {
        if ($type === ContactDetail::TYPE_ADDRESS) {
            return $this->moveTo($value);
        }

        return DB::transaction(function () use ($type, $value) {
            $detail = $this->findDetail($type, $value) ?? $this->details()->make(['type' => $type, 'value' => $value]);
            $hasPrimary = $this->details()->current()->where('type', $type)->where('is_primary', true)
                ->when($detail->exists, fn ($query) => $query->whereKeyNot($detail->getKey()))
                ->exists();

            $detail->forceFill(['is_primary' => ! $hasPrimary || $detail->is_primary, 'removed_at' => null])->save();

            return $detail;
        });
    }

    /**
     * Mark a detail removed: no longer in use, but kept for matching. When
     * it was the primary one, the most recently added current detail of its
     * type becomes primary.
     */
    public function removeDetail(ContactDetail $detail): void
    {
        DB::transaction(function () use ($detail) {
            $wasPrimary = $detail->is_primary;

            $detail->forceFill(['is_primary' => false, 'removed_at' => now()])->save();

            if ($wasPrimary && $detail->type !== ContactDetail::TYPE_ADDRESS) {
                $this->details()->current()->where('type', $detail->type)->latest('id')->first()
                    ?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * The contact's detail of this type with the same normalized value,
     * current or removed, or the same value as entered when there's nothing
     * to normalize, such as a phone number without an area code.
     */
    public function findDetail(string $type, string $value): ?ContactDetail
    {
        $normalized = ContactDetail::normalize($type, $value);

        return $this->details()
            ->where('type', $type)
            ->when(
                $normalized !== null,
                fn ($query) => $query->where('normalized', $normalized),
                fn ($query) => $query->whereNull('normalized')->where('value', trim($value)),
            )
            // A current one first
            ->orderByRaw('removed_at IS NOT NULL')
            ->first();
    }
}
