<?php

namespace App\Reports;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

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
     * Other contacts, not deleted, with one of the contact's current emails
     * or phone numbers as a current one of theirs, oldest first: each with
     * the shared values as the contact has them. A previous detail on either
     * side doesn't count, so changing a number settles it. The contact's
     * details must be loaded, as find() does.
     *
     * @return BaseCollection<int, array{contact: Contact, values: list<string>}>
     */
    public function sharing(Contact $contact): BaseCollection
    {
        $mine = $contact->details
            ->filter(fn (ContactDetail $detail) => $detail->type !== ContactDetail::TYPE_ADDRESS && $detail->normalized !== null && ! $detail->isRemoved())
            ->keyBy(fn (ContactDetail $detail) => "{$detail->type}:{$detail->normalized}");

        if ($mine->isEmpty()) {
            return collect();
        }

        return ContactDetail::query()
            ->current()
            ->with('contact')
            ->where(function ($query) use ($mine) {
                foreach ($mine as $detail) {
                    $query->orWhere(fn ($query) => $query->where('type', $detail->type)->where('normalized', $detail->normalized));
                }
            })
            ->where('contact_id', '!=', $contact->id)
            ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('contact_id')
            ->orderBy('id')
            ->get()
            ->groupBy('contact_id')
            ->map(fn (BaseCollection $details) => [
                'contact' => $details->first()->contact,
                'values' => $details->map(fn (ContactDetail $detail) => $mine["{$detail->type}:{$detail->normalized}"]->value)->unique()->values()->all(),
            ])
            ->values();
    }

    /**
     * Each form submission's contact, keyed by the submission's ID: the
     * contact of the leaf application it made, or else a contact, not
     * deleted, with its email, or else with its phone number as 10 digits,
     * current or previous, the oldest when there are several. Submissions
     * without one are left out.
     *
     * @param  BaseCollection<int, FormSubmission>  $submissions
     * @return array<int, Contact>
     */
    public function contactsFor(BaseCollection $submissions): array
    {
        if ($submissions->isEmpty()) {
            return [];
        }

        $submissions = (new Collection($submissions->all()))->loadMissing('leafApplication.contact');
        $phone = fn (FormSubmission $submission) => ContactDetail::normalize(ContactDetail::TYPE_PHONE, $submission->data['phone'] ?? null);

        $emails = $submissions->map(fn (FormSubmission $submission) => ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $submission->email))->filter()->unique()->values()->all();
        $phones = $submissions->map($phone)->filter()->unique()->values()->all();

        $byDetail = $emails === [] && $phones === [] ? collect() : ContactDetail::query()
            ->with('contact')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('type', ContactDetail::TYPE_EMAIL)->whereIn('normalized', $emails))
                ->orWhere(fn ($query) => $query->where('type', ContactDetail::TYPE_PHONE)->whereIn('normalized', $phones)))
            ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('contact_id')
            ->get()
            // The oldest contact for each value
            ->unique(fn (ContactDetail $detail) => "{$detail->type}:{$detail->normalized}")
            ->mapWithKeys(fn (ContactDetail $detail) => ["{$detail->type}:{$detail->normalized}" => $detail->contact]);

        $contacts = [];

        foreach ($submissions as $submission) {
            $applicationContact = $submission->leafApplication?->contact;
            $email = ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $submission->email);

            $contact = ($applicationContact && ! $applicationContact->trashed() ? $applicationContact : null)
                ?? ($email !== null ? $byDetail->get(ContactDetail::TYPE_EMAIL . ":{$email}") : null)
                ?? (($digits = $phone($submission)) !== null ? $byDetail->get(ContactDetail::TYPE_PHONE . ":{$digits}") : null);

            if ($contact !== null) {
                $contacts[$submission->id] = $contact;
            }
        }

        return $contacts;
    }

    /**
     * The contact a deleted contact was merged into, following merges of
     * merges to one that isn't deleted, or null when the contact wasn't
     * merged.
     */
    public function mergedInto(int $id): ?Contact
    {
        $contact = Contact::withTrashed()->find($id);

        // A handful of steps at most; the limit only guards against a loop
        for ($steps = 0; $contact?->trashed() && $contact->merged_into_id !== null && $steps < 20; $steps++) {
            $contact = $contact->mergedInto;
        }

        return $contact !== null && ! $contact->trashed() && $contact->id !== $id ? $contact : null;
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
