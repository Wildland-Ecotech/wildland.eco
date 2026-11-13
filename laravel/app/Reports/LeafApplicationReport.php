<?php

namespace App\Reports;

use App\Models\LeafApplication;
use Illuminate\Database\Eloquent\Collection;

/**
 * Leaf program applications, for leaves:applications, leaves:view, and the
 * admin pages.
 */
class LeafApplicationReport
{
    /**
     * Applications made in the last $days days, or ever with null, oldest
     * first. $types limits the list to some of LeafApplication::TYPES (drop
     * off or pickup); with none, both are listed. Soft-deleted applications
     * are left out, except applications marked as a test or spam with
     * $flagged.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, LeafApplication>
     */
    public function list(?int $days, array $types = [], bool $flagged = false): Collection
    {
        return LeafApplication::query()
            ->when($flagged, fn ($query) => $query->withTrashed()->where(
                fn ($query) => $query->whereNull('deleted_at')->orWhereNotNull('flagged_as'),
            ))
            ->when($days !== null, fn ($query) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when($types, fn ($query) => $query->whereIn('type', $types))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * One application by its ID, or null. A soft-deleted application is
     * found too, since it was asked for by ID.
     */
    public function find(int $id): ?LeafApplication
    {
        return LeafApplication::withTrashed()->find($id);
    }
}
