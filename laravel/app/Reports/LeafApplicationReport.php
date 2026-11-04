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
     * Applications made in the last $days days, oldest first. Soft-deleted
     * applications are left out.
     *
     * @return Collection<int, LeafApplication>
     */
    public function list(int $days): Collection
    {
        return LeafApplication::query()
            ->where('created_at', '>=', now()->subDays($days))
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
