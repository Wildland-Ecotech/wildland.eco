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
 * An application belongs to an existing contact only when they have the
 * same name (ignoring case and spacing) and share its email or phone number
 * (as 10 digits), current or removed. Deleted contacts are never matched.
 *
 * - One such contact: linked. The application's email and phone number
 *   become the contact's primary ones, since the newest application has
 *   the latest details, and its address becomes the contact's if they had
 *   none. A different current address is flagged, and left as it is.
 * - None: a new contact with the application's name, email, phone number,
 *   and address, even if another contact has the same email or phone
 *   number, since it may be someone else at the same number.
 * - More than one: linked to neither, and flagged.
 *
 * Whenever the application's email or phone number is also on another
 * contact, such as two people sharing a phone, or the same person with a
 * name written differently, the application is flagged so an admin can
 * merge them or leave them apart.
 */
class ContactLinker
{
    /**
     * What link() did: made a new contact, linked to an existing one, linked
     * to neither since more than one contact matched, or nothing, since the
     * application was already linked or flagged. Whether it needs a look is
     * in the application's `contact_review`.
     */
    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const CONFLICT = 'conflict';

    public const SKIPPED = 'skipped';

    /**
     * The details an application is matched on, and the review reason when
     * another contact shares one.
     */
    private const SHARED_REASONS = [
        ContactDetail::TYPE_EMAIL => LeafApplication::REVIEW_SHARED_EMAIL,
        ContactDetail::TYPE_PHONE => LeafApplication::REVIEW_SHARED_PHONE,
    ];

    /**
     * Link the application to its contact, making one if needed, and flag
     * anything that needs a look. Returns one of the outcomes above.
     */
    public function link(LeafApplication $application): string
    {
        if ($application->contact_id !== null || $application->contact_review !== null) {
            return self::SKIPPED;
        }

        return DB::transaction(function () use ($application) {
            $name = $this->comparableName($application->name);
            $sameName = $this->matches($application)
                ->filter(fn (Contact $contact) => $this->comparableName($contact->name) === $name)
                ->values();

            if ($sameName->count() > 1) {
                $application->forceFill(['contact_review' => [LeafApplication::REVIEW_CONFLICT]])->save();

                return self::CONFLICT;
            }

            $contact = $sameName->first();
            $created = $contact === null;
            $reasons = [];

            if ($created) {
                $contact = Contact::create(['name' => trim($application->name)]);
            } elseif ($this->movedAway($contact, $application)) {
                $reasons[] = LeafApplication::REVIEW_ADDRESS;
            }

            $this->useDetails($contact, $application);

            $reasons = [...$reasons, ...$this->sharedReasons($contact, $application)];

            $application->contact()->associate($contact);
            $application->forceFill(['contact_review' => $reasons === [] ? null : $reasons])->save();

            return $created ? self::CREATED : self::LINKED;
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
        $values = $this->matchValues($application);

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
     * The application's email and phone number as normalized for matching,
     * by type, leaving out any with nothing to match on.
     *
     * @return array<string, string>
     */
    private function matchValues(LeafApplication $application): array
    {
        return array_filter([
            ContactDetail::TYPE_EMAIL => ContactDetail::normalize(ContactDetail::TYPE_EMAIL, $application->email),
            ContactDetail::TYPE_PHONE => ContactDetail::normalize(ContactDetail::TYPE_PHONE, $application->phone),
        ]);
    }

    /**
     * Whether the contact has a current address other than the
     * application's.
     */
    private function movedAway(Contact $contact, LeafApplication $application): bool
    {
        $address = ContactDetail::normalize(ContactDetail::TYPE_ADDRESS, $application->address);
        $current = $contact->address();

        return $address !== null && $current !== null && $current->normalized !== $address;
    }

    /**
     * The review reasons for the application's email or phone number being
     * on any other contact, not deleted, current or removed.
     *
     * @return list<string>
     */
    private function sharedReasons(Contact $contact, LeafApplication $application): array
    {
        $reasons = [];

        foreach ($this->matchValues($application) as $type => $normalized) {
            $shared = ContactDetail::query()
                ->where('type', $type)
                ->where('normalized', $normalized)
                ->where('contact_id', '!=', $contact->id)
                ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
                ->exists();

            if ($shared) {
                $reasons[] = self::SHARED_REASONS[$type];
            }
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
