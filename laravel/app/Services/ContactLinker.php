<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links a leaf application to the person who made it, so every application
 * from the same person is on one contact. Nothing here ever stops an
 * application from being saved: linking happens after, and anything unclear
 * is left for an admin to look at rather than guessed.
 *
 * An application matches a contact, not deleted, with its email or phone
 * number (as 10 digits), current or removed:
 *
 * - No match: a new contact, with the application's name, email, phone
 *   number, and address.
 * - One match, with the same name and no other current address: linked.
 *   The application's email and phone number become the contact's primary
 *   ones, since the newest application has the latest details, and its
 *   address becomes the contact's if they had none.
 * - One match with a different name, or a different current address:
 *   linked, flagged for review, and the contact is left unchanged, since
 *   it may be someone else at the same number or a move.
 * - Its email and phone number match different contacts: linked to
 *   neither, and flagged for review.
 */
class ContactLinker
{
    /**
     * What link() did: made a new contact, linked to an existing one,
     * linked but flagged for review, flagged without linking, or nothing,
     * since the application was already linked or flagged.
     */
    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const FLAGGED = 'flagged';

    public const CONFLICT = 'conflict';

    public const SKIPPED = 'skipped';

    /**
     * Link the application to its contact, or flag it for review. Returns
     * one of the outcomes above.
     */
    public function link(LeafApplication $application): string
    {
        if ($application->contact_id !== null || $application->contact_review !== null) {
            return self::SKIPPED;
        }

        return DB::transaction(function () use ($application) {
            $matches = $this->matches($application);

            if ($matches->count() > 1) {
                $application->forceFill(['contact_review' => [LeafApplication::REVIEW_CONFLICT]])->save();

                return self::CONFLICT;
            }

            $contact = $matches->first();

            if ($contact === null) {
                $contact = Contact::create(['name' => trim($application->name)]);
                $this->useDetails($contact, $application);
                $application->contact()->associate($contact)->save();

                return self::CREATED;
            }

            $reasons = $this->reviewReasons($contact, $application);

            if ($reasons === []) {
                $this->useDetails($contact, $application);
            }

            $application->contact()->associate($contact);
            $application->forceFill(['contact_review' => $reasons === [] ? null : $reasons])->save();

            return $reasons === [] ? self::LINKED : self::FLAGGED;
        });
    }

    /**
     * Contacts, not deleted, with the application's email or phone number,
     * current or removed, oldest first.
     *
     * @return Collection<int, Contact>
     */
    private function matches(LeafApplication $application): Collection
    {
        $values = array_filter([
            ContactDetail::TYPE_EMAIL => ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $application->email),
            ContactDetail::TYPE_PHONE => ContactDetail::normalize(ContactDetail::TYPE_PHONE, $application->phone),
        ]);

        if ($values === []) {
            return collect();
        }

        return Contact::query()
            ->whereHas('details', function ($query) use ($values) {
                $query->where(function ($query) use ($values) {
                    foreach ($values as $type => $normalized) {
                        $query->orWhere(fn ($query) => $query->where('type', $type)->where('normalized', $normalized));
                    }
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Why linking to this contact needs a look: a different name, or a
     * different current address.
     *
     * @return list<string>
     */
    private function reviewReasons(Contact $contact, LeafApplication $application): array
    {
        $reasons = [];

        if ($this->comparableName($contact->name) !== $this->comparableName($application->name)) {
            $reasons[] = LeafApplication::REVIEW_NAME;
        }

        $address = ContactDetail::normalize(ContactDetail::TYPE_ADDRESS, $application->address);
        $current = $contact->address();

        if ($address !== null && $current !== null && $current->normalized !== $address) {
            $reasons[] = LeafApplication::REVIEW_ADDRESS;
        }

        return $reasons;
    }

    /**
     * Give the contact the application's details: its email and phone number
     * as the primary ones, and its address if the contact has none.
     */
    private function useDetails(Contact $contact, LeafApplication $application): void
    {
        foreach ([ContactDetail::TYPE_EMAIL => $application->email, ContactDetail::TYPE_PHONE => $application->phone] as $type => $value) {
            if (trim((string) $value) !== '') {
                $contact->usePrimary($type, $value);
            }
        }

        if (trim((string) $application->address) !== '' && $contact->address() === null) {
            $contact->moveTo($application->address);
        }
    }

    /**
     * A name lowercase with spacing collapsed, so "Jamie  Rivera" and
     * "jamie rivera" are the same person's.
     */
    private function comparableName(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $name)));
    }
}
