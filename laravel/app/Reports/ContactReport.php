<?php

namespace App\Reports;

use App\Models\Contact;
use App\Models\ContactDetail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contacts, for the admin contact pages.
 */
class ContactReport
{
    /**
     * Contacts, not deleted, with the person who applied most recently
     * first and contacts without an application last, newest first. Each
     * has its current details, `applications_count`, and
     * `applications_max_created_at` (when they last applied, or null).
     *
     * $search, when not empty, matches part of a name or an email, ignoring
     * case, or part of a phone number's digits, however it's written,
     * including previous emails and phone numbers.
     *
     * @return Collection<int, Contact>
     */
    public function list(string $search = ''): Collection
    {
        return Contact::query()
            ->with(['details' => fn ($query) => $query->current()->orderByDesc('is_primary')->orderBy('id')])
            ->withCount('applications')
            ->withMax('applications', 'created_at')
            ->withCasts(['applications_max_created_at' => 'datetime'])
            ->when($search !== '', fn ($query) => $this->search($query, $search))
            ->orderByRaw('applications_max_created_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One contact, not deleted, by its ID, with every detail, current
     * details first, and their applications, newest first; or null.
     */
    public function find(int $id): ?Contact
    {
        return Contact::query()
            ->with([
                'details' => fn ($query) => $query->orderByRaw('removed_at IS NOT NULL')->orderByDesc('is_primary')->orderBy('id'),
                'applications' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->find($id);
    }

    /**
     * Limit the query to contacts whose name, email, or phone number
     * matches $search.
     *
     * @param  Builder<Contact>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $text = '%' . addcslashes(mb_strtolower($search), '\\%_') . '%';
        $digits = preg_replace('/\D/', '', $search);

        $query->where(function ($query) use ($text, $digits) {
            $query->whereLike('name', $text)
                ->orWhereHas('details', function ($query) use ($text, $digits) {
                    $query->where(function ($query) use ($text, $digits) {
                        $query->where(fn ($query) => $query->where('type', ContactDetail::TYPE_EMAIL)->whereLike('normalized', $text));

                        // The digits as typed, so a number without an area
                        // code is found too. Three or more, so a name or an
                        // email never matches every phone number.
                        if (strlen($digits) >= 3) {
                            $query->orWhere(fn ($query) => $query->where('type', ContactDetail::TYPE_PHONE)
                                ->whereRaw("regexp_replace(value, '[^0-9]', '', 'g') LIKE ?", ["%{$digits}%"]));
                        }
                    });
                });
        });
    }
}
