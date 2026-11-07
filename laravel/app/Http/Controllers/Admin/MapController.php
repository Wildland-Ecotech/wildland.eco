<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLocationsRequest;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Reports\ContactReport;
use App\Reports\MapReport;
use App\Services\LocationImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Map page (/admin/map), for planning pickups and finding the
 * closest drop-off site: by default, pending pickups colored by when the
 * bags are ready, soonest first with a running total of bags, and the
 * drop-off sites; with `show=everything`, every located contact; with a
 * search, the contacts it finds and how far each drop-off site is. Under
 * it, the addresses that still need a location, as JSON to copy for the
 * local geocoder (`addresses:geocode`), and a box to paste what it printed
 * back: a preview first, with the locations it would save on the map, then
 * saving (MapReport, LocationImport).
 */
class MapController extends Controller
{
    public const SHOW_PICKUPS = 'pickups';

    public const SHOW_EVERYTHING = 'everything';

    public const SHOW_CHOICES = [
        self::SHOW_PICKUPS => 'Needs pickup',
        self::SHOW_EVERYTHING => 'Everything',
    ];

    public const MAX_SEARCH_LENGTH = 100;

    /**
     * The legend's words for each kind of dot.
     */
    public const KIND_LABELS = [
        MapReport::KIND_NOW => 'Ready now or within a week',
        MapReport::KIND_SOON => 'Ready in 1 to 2 weeks',
        MapReport::KIND_LATER => 'Ready in 2+ weeks',
        MapReport::KIND_OTHER => 'Contact',
        MapReport::KIND_SITE => 'Drop-off site',
        MapReport::KIND_PREVIEW => 'Not saved yet',
    ];

    /**
     * The pickups list's columns, the ones that make up a pickup's line on
     * a phone (name, ready, and bags), and those lined up on the right.
     */
    public const PICKUP_HEADERS = ['Name', 'Ready', 'Bags', 'Running total', 'Address', 'Phone'];

    public const PICKUP_SUMMARY_COLUMNS = [0, 1, 2];

    public const PICKUP_NUMBER_COLUMNS = [2, 3];

    /**
     * The preview's columns, and the ones on a phone's line (number and
     * result).
     */
    public const PREVIEW_HEADERS = ['Number', 'Address', 'Location', 'Result'];

    public const PREVIEW_SUMMARY_COLUMNS = [0, 3];

    public function index(Request $request, MapReport $report, ContactReport $contacts): View
    {
        return $this->page($request, $report, $contacts);
    }

    /**
     * Preview what `addresses:geocode` printed on the page, with the
     * locations that would be saved on the map, or, with `confirm`, save
     * them and go back to the map.
     */
    public function locations(SaveLocationsRequest $request, MapReport $report, ContactReport $contacts, LocationImport $import): View|RedirectResponse
    {
        $pasted = $request->validated('results');

        if ($request->boolean('confirm')) {
            $saved = $import->save($pasted, $request->user());

            return redirect()->route('admin.map')->with('status', $saved === 0
                ? 'No locations were saved.'
                : 'Saved ' . $saved . ' ' . Str::plural('location', $saved) . '.');
        }

        $preview = $import->preview($pasted);

        return $this->page($request, $report, $contacts, [
            'previewPoints' => array_values(array_map(
                fn (array $row) => [
                    'lat' => $row['latitude'],
                    'lng' => $row['longitude'],
                    'kind' => MapReport::KIND_PREVIEW,
                    'name' => $row['address']->contact->name,
                    'note' => 'Not saved yet · ' . $row['address']->value,
                    'url' => route('admin.contacts.show', $row['address']->contact_id),
                ],
                array_filter($preview, fn (array $row) => in_array($row['result'], [LocationImport::SAVE, LocationImport::REPLACE], true)),
            )),
            'pasted' => $pasted,
            'previewRows' => array_map(fn (array $row) => $this->previewRow($row), $preview),
            'saveable' => count(array_filter($preview, fn (array $row) => in_array($row['result'], [LocationImport::SAVE, LocationImport::REPLACE], true))),
        ]);
    }

    /**
     * The page for the query string's view or search, with anything else
     * it needs, such as a preview.
     *
     * @param  array<string, mixed>  $extra
     */
    private function page(Request $request, MapReport $report, ContactReport $contacts, array $extra = []): View
    {
        $search = $request->query('q');
        $search = is_string($search) ? Str::limit(trim($search), self::MAX_SEARCH_LENGTH, '') : '';
        $show = $request->query('show') === self::SHOW_EVERYTHING ? self::SHOW_EVERYTHING : self::SHOW_PICKUPS;
        $unlocated = $report->unlocated();

        $page = match (true) {
            $search !== '' => $this->searchPage($report, $contacts, $search),
            $show === self::SHOW_EVERYTHING => $this->everythingPage($report),
            default => $this->pickupsPage($report),
        };
        // A preview's locations, on the map with the rest until they're saved
        $page['points'] = [...$page['points'], ...($extra['previewPoints'] ?? [])];
        unset($extra['previewPoints']);

        return view('pages.admin.map', [
            'search' => $search,
            'show' => $show,
            'pickupRows' => [],
            'results' => [],
            ...$page,
            'legend' => collect($page['points'])->countBy('kind')->sortBy(fn (int $count, string $kind) => array_search($kind, array_keys(self::KIND_LABELS), true))->all(),
            'unlocated' => LocationImport::encode([LocationImport::ADDRESSES => $unlocated]),
            'unlocatedCount' => count($unlocated),
            'pasted' => $request->old('results', ''),
            'previewRows' => null,
            'saveable' => 0,
            ...$extra,
        ]);
    }

    /**
     * Pending pickups and the drop-off sites, with the pickups listed
     * soonest first and a running total of bags.
     *
     * @return array<string, mixed>
     */
    private function pickupsPage(MapReport $report): array
    {
        $pickups = $report->pickups();
        $located = $pickups->filter(fn (array $pickup) => $pickup['address']?->location !== null);
        $bags = $pickups->sum(fn (array $pickup) => $pickup['application']->estimated_bag_count);
        $total = 0;

        $summary = match (true) {
            $pickups->isEmpty() => 'Nobody is waiting for a pickup.',
            $located->count() === $pickups->count() => "{$this->count($pickups->count(), 'pickup')} waiting, " . number_format($bags) . ' bags in all, all on the map.',
            default => "{$this->count($pickups->count(), 'pickup')} waiting, " . number_format($bags) . " bags in all: {$located->count()} on the map, " . ($pickups->count() - $located->count()) . ' still ' . ($pickups->count() - $located->count() === 1 ? 'needs' : 'need') . ' a location.',
        };

        return [
            'summary' => $summary,
            'points' => [
                ...$located->map(fn (array $pickup) => $this->point($pickup['address'], $pickup['contact']->name, $pickup['kind'], $this->readyNote($pickup), $pickup['contact']->id))->values()->all(),
                ...$this->sitePoints($report),
            ],
            'pickupRows' => $pickups->map(function (array $pickup) use (&$total) {
                $application = $pickup['application'];
                $total += $application->estimated_bag_count;

                return $this->pickupRow($pickup, $total);
            })->all(),
        ];
    }

    /**
     * Every located contact, with counts for reporting.
     *
     * @return array<string, mixed>
     */
    private function everythingPage(MapReport $report): array
    {
        $everyone = $report->everything();
        $sites = $everyone->where('kind', MapReport::KIND_SITE)->count();
        $pickups = $everyone->whereIn('kind', [MapReport::KIND_NOW, MapReport::KIND_SOON, MapReport::KIND_LATER])->count();
        $others = $everyone->count() - $sites - $pickups;

        return [
            'summary' => $everyone->isEmpty()
                ? 'Nothing has a location yet.'
                : $this->count($everyone->count(), 'place') . " on the map: {$this->count($pickups, 'pickup')} waiting, {$this->count($others, 'other contact')}, and {$this->count($sites, 'drop-off site')}.",
            'points' => $everyone->map(fn (array $place) => $this->point($place['address'], $place['contact']->name, $place['kind'], $place['address']->value, $place['contact']->id))->all(),
        ];
    }

    /**
     * The contacts a search finds, each with the drop-off sites nearest
     * first, on the map with the sites.
     *
     * @return array<string, mixed>
     */
    private function searchPage(MapReport $report, ContactReport $contacts, string $search): array
    {
        $found = $report->search($search, $contacts);
        $results = $found['results'];
        $shown = $results->count();

        return [
            'summary' => match (true) {
                $found['total'] === 0 => "No contacts match “{$search}”.",
                $found['total'] > $shown => "The first {$shown} of {$found['total']} contacts matching “{$search}”.",
                default => $this->count($shown, 'contact') . " matching “{$search}”.",
            },
            'points' => [
                ...$results
                    ->filter(fn (array $result) => $result['address']?->location !== null && ! $result['contact']->is_drop_off_site)
                    ->map(fn (array $result) => $this->point($result['address'], $result['contact']->name, MapReport::KIND_OTHER, $result['address']->value, $result['contact']->id))
                    ->values()
                    ->all(),
                ...$this->sitePoints($report),
            ],
            'results' => $results->map(fn (array $result) => [
                'name' => $result['contact']->name,
                'url' => route('admin.contacts.show', $result['contact']->id),
                'details' => implode(' · ', array_filter([
                    $result['address']?->value,
                    $result['contact']->details->firstWhere('type', ContactDetail::TYPE_PHONE)?->value,
                ])),
                'located' => $result['address']?->location !== null,
                'sites' => $result['sites']->map(fn (array $site) => [
                    'name' => $site['contact']->name,
                    'distance' => number_format($site['miles'], 1) . ' mi',
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * The drop-off sites with a location, as points.
     *
     * @return list<array<string, mixed>>
     */
    private function sitePoints(MapReport $report): array
    {
        return $report->sites()
            ->filter(fn (array $site) => $site['address']?->location !== null)
            ->map(fn (array $site) => $this->point($site['address'], $site['contact']->name, MapReport::KIND_SITE, $site['address']->value, $site['contact']->id))
            ->values()
            ->all();
    }

    /**
     * A dot on the map, for resources/js/admin-map.js: where, its kind, and
     * what its popup says.
     *
     * @return array{lat: float, lng: float, kind: string, name: string, note: string, url: string}
     */
    private function point(ContactDetail $address, string $name, string $kind, string $note, int $contactId): array
    {
        return [
            'lat' => $address->location['latitude'],
            'lng' => $address->location['longitude'],
            'kind' => $kind,
            'name' => $name,
            'note' => $note,
            'url' => route('admin.contacts.show', $contactId),
        ];
    }

    /**
     * A pickup for the list: its name, with how soon as a label, when the
     * bags are ready, bags, the running total, the address (noting when
     * it isn't on the map yet), and a phone number. It links to the
     * contact, or to the application before it's linked to one.
     *
     * @param  array{application: LeafApplication, contact: ?\App\Models\Contact, address: ?ContactDetail, kind: string, days: ?int}  $pickup
     * @return array<string, mixed>
     */
    private function pickupRow(array $pickup, int $total): array
    {
        $application = $pickup['application'];
        $contact = $pickup['contact'];
        $address = $pickup['address']?->value ?? $application->address;
        $phone = $contact?->details()->current()->where('type', ContactDetail::TYPE_PHONE)->orderByDesc('is_primary')->value('value') ?? $application->phone;
        $ready = $this->ready($pickup);

        return [
            'cells' => [
                $contact?->name ?? $application->name,
                $ready,
                $application->estimated_bag_count,
                $total,
                $pickup['address']?->location !== null ? $address : "{$address} (not on the map yet)",
                $phone,
            ],
            'summary' => [$contact?->name ?? $application->name, $ready, $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count)],
            'badge' => match (true) {
                $pickup['kind'] === MapReport::KIND_NOW && ($pickup['days'] ?? 0) <= 0 => 'Ready now',
                $pickup['kind'] === MapReport::KIND_NOW => 'This week',
                $pickup['kind'] === MapReport::KIND_SOON => 'In 1 to 2 weeks',
                default => 'In 2+ weeks',
            },
            'badgeTone' => match ($pickup['kind']) {
                MapReport::KIND_NOW => 'urgent',
                MapReport::KIND_SOON => 'soon',
                default => null,
            },
            'url' => $contact ? route('admin.contacts.show', $contact->id) : route('admin.leaves.show', $application->id),
        ];
    }

    /**
     * A previewed entry: the number (or the entry, when it has none), the
     * address it names, the location, and what it would do.
     *
     * @param  array{entry: string, id: ?int, latitude: ?float, longitude: ?float, address: ?ContactDetail, result: string}  $row
     * @return array<string, mixed>
     */
    private function previewRow(array $row): array
    {
        $result = LocationImport::RESULT_LABELS[$row['result']];
        $saves = in_array($row['result'], [LocationImport::SAVE, LocationImport::REPLACE], true);

        return [
            'cells' => [
                $row['id'] ?? $row['entry'],
                $row['address']?->value ?? '',
                $row['latitude'] !== null ? "{$row['latitude']}, {$row['longitude']}" : '',
                ['text' => $result, 'status' => $saves ? 'success' : 'failure'],
            ],
            'summary' => [(string) ($row['id'] ?? $row['entry']), $result],
        ];
    }

    /**
     * When a pickup's bags are ready: "Now", or a date such as "Nov 10".
     *
     * @param  array{application: LeafApplication}  $pickup
     */
    private function ready(array $pickup): string
    {
        return $pickup['application']->ready_now ? 'Now' : $pickup['application']->ready_date->format('M j');
    }

    /**
     * A pickup's popup line, such as "Ready now · 40 bags" or "Ready Nov 10
     * · 60 bags".
     *
     * @param  array{application: LeafApplication}  $pickup
     */
    private function readyNote(array $pickup): string
    {
        $bags = $pickup['application']->estimated_bag_count;

        $ready = $pickup['application']->ready_now ? 'Ready now' : 'Ready ' . $this->ready($pickup);

        return $ready . ' · ' . $bags . ' ' . Str::plural('bag', $bags);
    }

    /**
     * A count and a noun, such as "1 pickup" or "6 pickups".
     */
    private function count(int $count, string $noun): string
    {
        return $count . ' ' . Str::plural($noun, $count);
    }
}
