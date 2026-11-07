<?php

namespace App\Reports;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the admin Map page shows: pending pickups, soonest first, colored by
 * when the bags are ready; the drop-off sites; every located contact; a
 * contact found by search with how far each drop-off site is; and the
 * addresses that still need a location, to copy for the local geocoder.
 * Locations are addresses' (ContactDetail::location); an application is on
 * the map through its contact's current address.
 */
class MapReport
{
    /**
     * Dates are Fort Collins dates.
     */
    public const TIMEZONE = 'America/Denver';

    /**
     * Pickups ready now, overdue, or within a week are red (now); within
     * two weeks, yellow (soon); later, green (later). Other contacts are
     * blue (other), drop-off sites gray (site), and pasted locations not
     * saved yet purple (preview).
     */
    public const NOW_DAYS = 7;

    public const SOON_DAYS = 14;

    public const KIND_NOW = 'now';

    public const KIND_SOON = 'soon';

    public const KIND_LATER = 'later';

    public const KIND_OTHER = 'other';

    public const KIND_SITE = 'site';

    public const KIND_PREVIEW = 'preview';

    public const MAX_SEARCH_RESULTS = 10;

    public const EARTH_RADIUS_MILES = 3958.8;

    /**
     * Pending pickup applications, not deleted, soonest first: ready now in
     * the order they applied, then by ready date. Each with its contact
     * (if linked), the contact's current address (with its location, if
     * any), how urgent it is, and how many days until the bags are ready
     * (null when ready now; zero or less when the date has come).
     *
     * @return Collection<int, array{application: LeafApplication, contact: ?Contact, address: ?ContactDetail, kind: string, days: ?int}>
     */
    public function pickups(): Collection
    {
        $today = Carbon::today(self::TIMEZONE);

        return LeafApplication::pending()
            ->where('type', LeafApplication::TYPE_PICKUP)
            ->with(['contact.details' => fn ($query) => $query->current()->where('type', ContactDetail::TYPE_ADDRESS)])
            ->get()
            ->map(function (LeafApplication $application) use ($today) {
                $days = $application->ready_now
                    ? null
                    : (int) round($today->diffInDays(Carbon::createFromFormat('Y-m-d', $application->ready_date->toDateString(), self::TIMEZONE)->startOfDay(), false));
                $contact = $application->contact && ! $application->contact->trashed() ? $application->contact : null;

                return [
                    'application' => $application,
                    'contact' => $contact,
                    'address' => $contact?->details->first(),
                    'kind' => match (true) {
                        $days === null || $days <= self::NOW_DAYS => self::KIND_NOW,
                        $days <= self::SOON_DAYS => self::KIND_SOON,
                        default => self::KIND_LATER,
                    },
                    'days' => $days,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $this->readyOn($a) <=> $this->readyOn($b),
                fn (array $a, array $b) => $a['application']->created_at <=> $b['application']->created_at,
                fn (array $a, array $b) => $a['application']->id <=> $b['application']->id,
            ])
            ->values();
    }

    /**
     * The drop-off sites, not deleted, by name, each with its current
     * address, if any.
     *
     * @return Collection<int, array{contact: Contact, address: ?ContactDetail}>
     */
    public function sites(): Collection
    {
        return Contact::query()
            ->where('is_drop_off_site', true)
            ->with(['details' => fn ($query) => $query->current()->where('type', ContactDetail::TYPE_ADDRESS)])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Contact $contact) => ['contact' => $contact, 'address' => $contact->details->first()]);
    }

    /**
     * Every contact, not deleted, whose current address has a location,
     * oldest first: drop-off sites as sites, those with a pending pickup in
     * its color, and everyone else as others.
     *
     * @return Collection<int, array{contact: Contact, address: ContactDetail, kind: string}>
     */
    public function everything(): Collection
    {
        $pickupKinds = $this->pickups()
            ->filter(fn (array $pickup) => $pickup['contact'] !== null)
            ->unique(fn (array $pickup) => $pickup['contact']->id)
            ->mapWithKeys(fn (array $pickup) => [$pickup['contact']->id => $pickup['kind']]);

        return ContactDetail::query()
            ->current()
            ->where('type', ContactDetail::TYPE_ADDRESS)
            ->whereNotNull('location')
            ->whereHas('contact', fn ($query) => $query->withoutTrashed())
            ->with('contact')
            ->orderBy('contact_id')
            ->get()
            ->map(fn (ContactDetail $address) => [
                'contact' => $address->contact,
                'address' => $address,
                'kind' => match (true) {
                    $address->contact->is_drop_off_site => self::KIND_SITE,
                    $pickupKinds->has($address->contact_id) => $pickupKinds[$address->contact_id],
                    default => self::KIND_OTHER,
                },
            ]);
    }

    /**
     * Contacts matching a name, phone number, or email, as Contacts'
     * search finds them, up to MAX_SEARCH_RESULTS: each with their current
     * address and the drop-off sites with a location, nearest first, with
     * the straight-line distance in miles when the contact has a location.
     *
     * @return array{total: int, results: Collection<int, array{contact: Contact, address: ?ContactDetail, sites: Collection<int, array{contact: Contact, miles: float}>}>}
     */
    public function search(string $search, ContactReport $contacts): array
    {
        $found = $contacts->list($search);
        $sites = $this->sites()->filter(fn (array $site) => $site['address']?->location !== null);

        return [
            'total' => $found->count(),
            'results' => $found->take(self::MAX_SEARCH_RESULTS)->map(function (Contact $contact) use ($sites) {
                $address = $contact->details->firstWhere('type', ContactDetail::TYPE_ADDRESS);
                $location = $address?->location;

                return [
                    'contact' => $contact,
                    'address' => $address,
                    'sites' => $location === null ? collect() : $sites
                        ->map(fn (array $site) => ['contact' => $site['contact'], 'miles' => $this->miles($location, $site['address']->location)])
                        ->sortBy('miles')
                        ->values(),
                ];
            })->values(),
        ];
    }

    /**
     * Current addresses, of contacts not deleted, without a location, to
     * copy for the geocoder: drop-off sites first, then those waiting for a
     * pickup, then those waiting to drop off, then everyone else, oldest
     * first in each. Each is the address's ID and the address without a
     * trailing ZIP code, which the geocoder matches better without, for the
     * JSON in LocationImport.
     *
     * @return list<array{id: int, address: string}>
     */
    public function unlocated(): array
    {
        $pending = LeafApplication::pending()->whereNotNull('contact_id')->get(['contact_id', 'type']);
        $pickups = $pending->where('type', LeafApplication::TYPE_PICKUP)->pluck('contact_id')->flip();
        $dropOffs = $pending->where('type', LeafApplication::TYPE_DROP_OFF)->pluck('contact_id')->flip();

        return ContactDetail::query()
            ->current()
            ->where('type', ContactDetail::TYPE_ADDRESS)
            ->whereNull('location')
            ->whereHas('contact', fn ($query) => $query->withoutTrashed())
            ->with('contact')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (ContactDetail $address) => match (true) {
                $address->contact->is_drop_off_site => 0,
                $pickups->has($address->contact_id) => 1,
                $dropOffs->has($address->contact_id) => 2,
                default => 3,
            })
            ->map(fn (ContactDetail $address) => ['id' => $address->id, 'address' => self::withoutZip($address->value)])
            ->values()
            ->all();
    }

    /**
     * An address without a trailing five-digit ZIP code or ZIP+4, such as
     * "123 Example St, Fort Collins, CO" from "…, CO 80521-1234". Tabs and
     * line breaks become spaces, so each address stays on one line.
     */
    public static function withoutZip(string $address): string
    {
        $address = trim(preg_replace('/\s+/', ' ', $address));

        return trim(preg_replace('/[\s,]+\d{5}(?:-\d{4})?$/', '', $address));
    }

    /**
     * The straight-line distance between two points, in miles.
     *
     * @param  array{latitude: float, longitude: float}  $from
     * @param  array{latitude: float, longitude: float}  $to
     */
    public function miles(array $from, array $to): float
    {
        $latitudeFrom = deg2rad($from['latitude']);
        $latitudeTo = deg2rad($to['latitude']);
        $a = sin(($latitudeTo - $latitudeFrom) / 2) ** 2
            + cos($latitudeFrom) * cos($latitudeTo) * sin(deg2rad($to['longitude'] - $from['longitude']) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_MILES * asin(min(1, sqrt($a)));
    }

    /**
     * The date a pickup's bags are ready, for sorting: the day they applied
     * when ready now, as "2026-11-06".
     *
     * @param  array{application: LeafApplication}  $pickup
     */
    private function readyOn(array $pickup): string
    {
        $application = $pickup['application'];

        return $application->ready_now
            ? $application->created_at->copy()->setTimezone(self::TIMEZONE)->toDateString()
            : $application->ready_date->toDateString();
    }
}
