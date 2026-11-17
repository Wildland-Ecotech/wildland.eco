<?php

namespace App\Reports;

use App\Models\ContactActivity;
use App\Models\LeafApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything waiting for an admin to look, for the admin Needs Review page
 * and its counts: applications whose contact needs review, drop-offs with
 * no drop-off site (logged before sites were recorded), and drop-offs with
 * no contact yet, from every season. Deleted entries, and entries of
 * deleted contacts, are left out.
 */
class ReviewReport
{
    /**
     * Applications whose contact needs review, oldest first, with their
     * contact.
     *
     * @return Collection<int, LeafApplication>
     */
    public function applications(): Collection
    {
        return LeafApplication::needingContactReview()->with('contact')->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Drop-offs with a contact and no drop-off site, oldest first, with
     * their contact.
     *
     * @return Collection<int, ContactActivity>
     */
    public function dropOffsWithNoSite(): Collection
    {
        return $this->dropOffsWithNoSiteQuery()->with('contact')->orderBy('happened_at')->orderBy('id')->get();
    }

    /**
     * Drop-offs with no contact yet, oldest first, with their drop-off
     * site.
     *
     * @return Collection<int, ContactActivity>
     */
    public function unknownDropOffs(): Collection
    {
        return $this->unknownDropOffsQuery()->with('dropOffSite')->orderBy('happened_at')->orderBy('id')->get();
    }

    /**
     * How many things need review in all.
     */
    public function count(): int
    {
        return LeafApplication::needingContactReview()->count()
            + $this->dropOffsWithNoSiteQuery()->count()
            + $this->unknownDropOffsQuery()->count();
    }

    /**
     * Whether an entry needs review: a drop-off with no contact, or with no
     * drop-off site.
     */
    public static function entryNeedsReview(ContactActivity $entry): bool
    {
        return $entry->type === ContactActivity::TYPE_DROP_OFF && ($entry->isUnknown() || $entry->drop_off_site_id === null);
    }

    /**
     * @return Builder<ContactActivity>
     */
    private function dropOffsWithNoSiteQuery(): Builder
    {
        return ContactActivity::query()
            ->where('type', ContactActivity::TYPE_DROP_OFF)
            ->whereNull('drop_off_site_id')
            ->whereHas('contact', fn ($query) => $query->withoutTrashed());
    }

    /**
     * @return Builder<ContactActivity>
     */
    private function unknownDropOffsQuery(): Builder
    {
        return ContactActivity::query()->whereNull('contact_id');
    }
}
