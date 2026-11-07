<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settling an application's contact by hand, from the admin application
 * page: the other contacts it could belong to, marking its review done, and
 * moving or linking it to another contact or a new one.
 */
class ApplicationReview
{
    /**
     * Contacts, not deleted, other than the one the application is linked
     * to, that share its email or phone number, current or removed, oldest
     * first. Each comes with the types it shares, email first.
     *
     * @return Collection<int, array{contact: Contact, types: list<string>}>
     */
    public function candidates(LeafApplication $application): Collection
    {
        $values = array_filter([
            ContactDetail::TYPE_EMAIL => ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $application->email),
            ContactDetail::TYPE_PHONE => ContactDetail::normalize(ContactDetail::TYPE_PHONE, $application->phone),
        ]);

        if ($values === []) {
            return collect();
        }

        return ContactDetail::query()
            ->with('contact')
            ->where(function ($query) use ($values) {
                foreach ($values as $type => $normalized) {
                    $query->orWhere(fn ($query) => $query->where('type', $type)->where('normalized', $normalized));
                }
            })
            ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
            ->when($application->contact_id !== null, fn ($query) => $query->where('contact_id', '!=', $application->contact_id))
            ->orderBy('contact_id')
            ->get()
            ->groupBy('contact_id')
            ->map(fn (Collection $details) => [
                'contact' => $details->first()->contact,
                'types' => array_values(array_intersect(array_keys($values), $details->pluck('type')->unique()->all())),
            ])
            ->values();
    }

    /**
     * Record that an admin looked at the application's contact and nothing
     * needs to change.
     */
    public function markReviewed(LeafApplication $application, User $admin): void
    {
        $application->forceFill(['contact_reviewed_at' => now(), 'contact_reviewed_by' => $admin->id])->save();
    }

    /**
     * Link the application to $contact, or to a new contact made from it
     * with null, and mark any review done. An existing contact gets the
     * application's email and phone number, not as the main ones, and its
     * address if they have none, since the admin has said it's the same
     * person. Returns the contact.
     */
    public function move(LeafApplication $application, ?Contact $contact, User $admin): Contact
    {
        return DB::transaction(function () use ($application, $contact, $admin) {
            if ($contact === null) {
                $contact = Contact::create(['name' => trim($application->name)]);
                $this->giveDetails($contact, $application, fn (string $type, string $value) => $contact->usePrimary($type, $value));
            } else {
                $this->giveDetails($contact, $application, fn (string $type, string $value) => $contact->addDetail($type, $value));
            }

            $application->contact()->associate($contact);

            if ($application->contact_review !== null) {
                $application->forceFill(['contact_reviewed_at' => now(), 'contact_reviewed_by' => $admin->id]);
            }

            $application->save();

            return $contact;
        });
    }

    /**
     * Give the contact the application's email and phone number with
     * $add, and its address if the contact has none.
     */
    private function giveDetails(Contact $contact, LeafApplication $application, callable $add): void
    {
        foreach ([ContactDetail::TYPE_EMAIL => $application->email, ContactDetail::TYPE_PHONE => $application->phone] as $type => $value) {
            if (trim((string) $value) !== '') {
                $add($type, $value);
            }
        }

        if (trim((string) $application->address) !== '' && $contact->address() === null) {
            $contact->moveTo($application->address);
        }
    }
}
