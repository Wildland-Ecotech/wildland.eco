<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLocationsRequest;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use App\Reports\ContactReport;
use App\Reports\MapReport;
use App\Services\LocationImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Map page (/admin/map), for planning pickups and finding the
 * closest drop-off site: by default, pending pickups not yet in a trailer
 * load (Needs scheduling), colored by when the bags are ready, soonest first
 * with a running total of bags, and the drop-off sites; with `build=1`, a
 * new trailer load, chosen by clicking pickups on the map or checking them
 * in the list (TrailerLoadController saves it); with `load={id}`, one
 * load's stops in route order, to follow on a phone, and with `edit=1`, the
 * same form for a planned load's date, capacity, and stops; with
 * `show=everything`, every located contact, those in a planned load ringed;
 * with a search, the contacts it finds and how far each drop-off site is;
 * and with `contact={id}`, from a contact's address, that contact the same
 * way.
 * Every view but an opened load lists the trailer loads above the map.
 * Under
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
        self::SHOW_PICKUPS => 'Needs scheduling',
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
     * The planned trailer loads, listed above the map, the ones on a
     * phone's line (date and bags), and those lined up on the right.
     */
    public const LOAD_HEADERS = ['Date', 'Stops', 'Bags'];

    public const LOAD_SUMMARY_COLUMNS = [0, 2];

    public const LOAD_NUMBER_COLUMNS = [1, 2];

    /**
     * The done and canceled loads, folded under the planned ones.
     */
    public const PAST_LOAD_HEADERS = ['Date', 'Status', 'Stops', 'Bags'];

    public const PAST_LOAD_SUMMARY_COLUMNS = [0, 1, 3];

    public const PAST_LOAD_NUMBER_COLUMNS = [2, 3];

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
        $loadId = $request->query('load');
        $load = is_string($loadId) && ctype_digit($loadId) ? TrailerLoad::find((int) $loadId) : null;
        abort_if($loadId !== null && $load === null, 404);
        // One contact, from the link on their address; an unknown or deleted one is a 404
        $contactId = $request->query('contact');
        $focus = is_string($contactId) && ctype_digit($contactId) ? $report->contact((int) $contactId) : null;
        abort_if($contactId !== null && $focus === null, 404);
        $building = $show === self::SHOW_PICKUPS && $search === '' && $load === null && $focus === null && $request->boolean('build');
        $editing = $load !== null && $load->isPlanned() && $request->boolean('edit');
        $unlocated = $report->unlocated();

        $page = match (true) {
            $editing => $this->editPage($request, $report, $load),
            $load !== null => $this->loadPage($report, $load),
            $focus !== null => $this->contactPage($report, $focus),
            $search !== '' => $this->searchPage($report, $contacts, $search),
            $show === self::SHOW_EVERYTHING => $this->everythingPage($report),
            default => $this->pickupsPage($report, $building ? $this->chosenStops($request) : null),
        };
        // A preview's locations, on the map with the rest until they're saved
        $page['points'] = [...$page['points'], ...($extra['previewPoints'] ?? [])];
        unset($extra['previewPoints']);

        return view('pages.admin.map', [
            'title' => 'Map',
            'search' => $search,
            'show' => $show,
            'focused' => $focus !== null,
            'load' => null,
            // The form for a new or edited load, with its words and where it goes
            'builder' => match (true) {
                $building => [
                    'title' => 'New Trailer Load',
                    'hint' => 'Click pickups on the map, or check them in the list, to add them. Click again to take one out.',
                    'action' => route('admin.map.loads.store'),
                    'button' => 'Save Load',
                    'cancel' => route('admin.map'),
                ],
                $editing => [
                    'title' => 'Edit Load',
                    'hint' => 'Click pickups on the map, or check them in the list, to add them or take them out. New stops go at the end; move them into place after saving.',
                    'action' => route('admin.map.loads.update', $load->id),
                    'button' => 'Save Changes',
                    'cancel' => route('admin.map', ['load' => $load->id]),
                ],
                default => null,
            },
            'editing' => $editing,
            'canEdit' => false,
            'loadOrder' => [],
            'pickupRows' => [],
            // Above the map on every view but an opened load
            ...($load === null ? $this->loadLists($report) : ['plannedLoadRows' => [], 'pastLoadRows' => []]),
            'stops' => [],
            'showPickups' => false,
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
     * Pending pickups not yet in a planned trailer load, and the drop-off
     * sites, with the pickups listed soonest first and a running total of
     * bags. When building or editing a load, $chosen holds the applications
     * chosen so far, and each pickup gets a box to add it, with its ID on
     * its dot; when editing, $editing's own stops are listed too, and the
     * form starts with its date and capacity.
     *
     * @param  list<int>|null  $chosen
     * @return array<string, mixed>
     */
    private function pickupsPage(MapReport $report, ?array $chosen, ?TrailerLoad $editing = null): array
    {
        ['pickups' => $pickups, 'scheduled' => $scheduled] = $report->toSchedule($editing);
        $located = $pickups->filter(fn (array $pickup) => $pickup['address']?->location !== null);
        $bags = $pickups->sum(fn (array $pickup) => $pickup['application']->estimated_bag_count);
        $total = 0;
        $needs = $pickups->count() === 1 ? 'needs' : 'need';

        $summary = match (true) {
            $pickups->isEmpty() && $scheduled > 0 => 'Every pickup is in a planned trailer load.',
            $pickups->isEmpty() => 'Nobody is waiting for a pickup.',
            $located->count() === $pickups->count() => "{$this->count($pickups->count(), 'pickup')} {$needs} scheduling, " . number_format($bags) . ' bags in all, all on the map.',
            default => "{$this->count($pickups->count(), 'pickup')} {$needs} scheduling, " . number_format($bags) . " bags in all: {$located->count()} on the map, " . ($pickups->count() - $located->count()) . ' still ' . ($pickups->count() - $located->count() === 1 ? 'needs' : 'need') . ' a location.',
        };

        if ($scheduled > 0 && $pickups->isNotEmpty()) {
            $summary .= ' ' . $scheduled . ' more ' . ($scheduled === 1 ? 'is' : 'are') . ' in a planned load.';
        }

        $page = [
            'summary' => $summary,
            'points' => [
                ...$located->map(fn (array $pickup) => $this->point($pickup['address'], $pickup['contact']->name, $pickup['kind'], $this->readyNote($pickup), $pickup['contact']->id, ['id' => $pickup['application']->id]))->values()->all(),
                ...$this->sitePoints($report),
            ],
            'pickupRows' => $pickups->map(function (array $pickup) use (&$total, $chosen) {
                $application = $pickup['application'];
                $total += $application->estimated_bag_count;

                return [
                    ...$this->pickupRow($pickup, $total),
                    'value' => $application->id,
                    'checked' => in_array($application->id, $chosen ?? [], true),
                ];
            })->all(),
        ];

        if ($chosen === null) {
            return $page;
        }

        // A new or edited load: what each pickup weighs, for the progress bar, and how full the chosen ones make it
        $bagsById = $pickups->mapWithKeys(fn (array $pickup) => [$pickup['application']->id => $pickup['application']->estimated_bag_count])->all();
        $chosenBags = array_sum(array_intersect_key($bagsById, array_flip($chosen)));
        // The capacity as it was typed, such as "1,500", without the commas
        // and spaces SaveTrailerLoadRequest takes out, since the flashed input
        // is what was sent
        $capacity = preg_replace('/[\s,]/', '', (string) old('capacity', (string) ($editing?->capacity ?? TrailerLoad::DEFAULT_CAPACITY)));

        return [
            ...$page,
            'loadBags' => $bagsById,
            'progress' => TrailerLoad::progress(count(array_intersect($chosen, array_keys($bagsById))), $chosenBags, (int) $capacity),
            'plannedOn' => old('planned_on', $editing?->planned_on->toDateString() ?? Carbon::today(MapReport::TIMEZONE)->toDateString()),
            'capacity' => $capacity,
        ];
    }

    /**
     * The trailer loads for the list above the map: the planned ones,
     * soonest first, and the done and canceled ones, newest first, each
     * opening the load.
     *
     * @return array{plannedLoadRows: list<array<string, mixed>>, pastLoadRows: list<array<string, mixed>>}
     */
    private function loadLists(MapReport $report): array
    {
        [$planned, $past] = $report->loads()->partition(fn (TrailerLoad $load) => $load->status === TrailerLoad::STATUS_PLANNED);

        $row = function (TrailerLoad $load, bool $withStatus) {
            $status = TrailerLoad::STATUS_LABELS[$load->status];
            $bags = number_format($load->applications->sum('estimated_bag_count')) . ' of ' . number_format($load->capacity);

            return [
                'cells' => [
                    $load->label(),
                    ...($withStatus ? [['badge' => $status, 'tone' => 'muted', 'text' => '']] : []),
                    $load->applications->count(),
                    $bags,
                ],
                'summary' => [$load->label(), ...($withStatus ? [$status] : []), $bags . ' bags'],
                'url' => route('admin.map', ['load' => $load->id]),
            ];
        };

        return [
            'plannedLoadRows' => $planned->map(fn (TrailerLoad $load) => $row($load, false))->values()->all(),
            'pastLoadRows' => $past->map(fn (TrailerLoad $load) => $row($load, true))->values()->all(),
        ];
    }

    /**
     * Editing a planned load: the builder with its date, capacity, and
     * stops chosen (or what was sent, when the form comes back with
     * errors), its pending stops listed with the pickups not in another
     * load, and their route order so the map numbers them that way, new ones
     * after.
     *
     * @return array<string, mixed>
     */
    private function editPage(Request $request, MapReport $report, TrailerLoad $load): array
    {
        $own = $report->stops($load)
            ->filter(fn (array $stop) => ! $stop['application']->trashed() && $stop['application']->status === LeafApplication::STATUS_PENDING)
            ->map(fn (array $stop) => $stop['application']->id)
            ->values()
            ->all();
        $chosen = $request->hasSession() && $request->session()->hasOldInput() ? $this->chosenStops($request) : $own;

        return [
            ...$this->pickupsPage($report, $chosen, $load),
            'title' => "Trailer Load: {$load->label()}",
            'load' => $load,
            'summary' => TrailerLoad::STATUS_LABELS[$load->status] . ' · ' . $this->count(count($own), 'stop'),
            'loadOrder' => $own,
        ];
    }

    /**
     * One trailer load: its stops on the map, numbered in route order, with
     * the drop-off sites, and listed with what's needed on the road, to
     * follow on a phone.
     *
     * @return array<string, mixed>
     */
    private function loadPage(MapReport $report, TrailerLoad $load): array
    {
        $stops = $report->stops($load);
        $located = $stops->filter(fn (array $stop) => $stop['address']?->location !== null);
        $bags = $stops->sum(fn (array $stop) => $stop['application']->estimated_bag_count);
        $count = $this->count($stops->count(), 'stop');
        // A done load says what was picked up at each stop
        $done = $load->status === TrailerLoad::STATUS_DONE;
        $logged = $done
            ? ContactActivity::whereIn('id', $stops->map(fn (array $stop) => $stop['application']->pivot->contact_activity_id)->filter()->all())->pluck('bag_count', 'id')
            : collect();
        $cards = $stops->map(fn (array $stop) => [
            ...$this->stop($stop),
            'pickup' => $done ? $this->pickupResult($stop, $logged->all()) : null,
        ])->all();

        return [
            'title' => "Trailer Load: {$load->label()}",
            'load' => $load,
            'summary' => TrailerLoad::STATUS_LABELS[$load->status] . " · {$count}" . ($located->count() === $stops->count() ? '' : ' · ' . ($stops->count() - $located->count()) . ' not on the map yet'),
            'progress' => TrailerLoad::progress($stops->count(), $bags, $load->capacity),
            'points' => [
                ...$located->map(fn (array $stop) => $this->point(
                    $stop['address'],
                    $stop['contact']->name,
                    $stop['kind'],
                    "Stop {$stop['position']} · {$stop['application']->estimated_bag_count} " . Str::plural('bag', $stop['application']->estimated_bag_count),
                    $stop['contact']->id,
                    ['number' => $stop['position']],
                ))->values()->all(),
                ...$this->sitePoints($report),
            ],
            'stops' => $cards,
            'showPickups' => $done,
            'canEdit' => $load->isPlanned(),
        ];
    }

    /**
     * What happened at a done load's stop: the pickup logged when the load
     * was marked done, such as "Picked up 28 bags", "Picked up separately"
     * for one whose pickup was logged on its own, or "Not picked up".
     *
     * @param  array{application: LeafApplication}  $stop
     * @param  array<int, int>  $logged  bags by the ID of the pickup logged
     */
    private function pickupResult(array $stop, array $logged): string
    {
        $activityId = $stop['application']->pivot->contact_activity_id;

        return match (true) {
            $activityId !== null && isset($logged[$activityId]) => 'Picked up ' . number_format($logged[$activityId]) . ' ' . Str::plural('bag', $logged[$activityId]),
            $stop['application']->status === LeafApplication::STATUS_COMPLETE => 'Picked up separately',
            default => 'Not picked up',
        };
    }

    /**
     * Every located contact, with counts for reporting, the pickups in a
     * planned trailer load ringed.
     *
     * @return array<string, mixed>
     */
    private function everythingPage(MapReport $report): array
    {
        $everyone = $report->everything();
        $scheduled = $report->scheduled()
            ->filter(fn (array $stop) => $stop['contact'] !== null)
            ->keyBy(fn (array $stop) => $stop['contact']->id);
        $sites = $everyone->where('kind', MapReport::KIND_SITE)->count();
        $pickups = $everyone->whereIn('kind', [MapReport::KIND_NOW, MapReport::KIND_SOON, MapReport::KIND_LATER])->count();
        $others = $everyone->count() - $sites - $pickups;

        return [
            'summary' => $everyone->isEmpty()
                ? 'Nothing has a location yet.'
                : $this->count($everyone->count(), 'place') . " on the map: {$this->count($pickups, 'pickup')} waiting, {$this->count($others, 'other contact')}, and {$this->count($sites, 'drop-off site')}.",
            'points' => $everyone->map(function (array $place) use ($scheduled) {
                $stop = $scheduled->get($place['contact']->id);

                return $stop === null
                    ? $this->point($place['address'], $place['contact']->name, $place['kind'], $place['address']->value, $place['contact']->id)
                    : $this->point($place['address'], $place['contact']->name, $place['kind'], "In the {$stop['load']->label()} load · stop {$stop['position']}", $place['contact']->id, ['ringed' => true]);
            })->all(),
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
        $shown = $found['results']->count();

        return [
            'summary' => match (true) {
                $found['total'] === 0 => "No contacts match “{$search}”.",
                $found['total'] > $shown => "The first {$shown} of {$found['total']} contacts matching “{$search}”.",
                default => $this->count($shown, 'contact') . " matching “{$search}”.",
            },
            ...$this->resultsPage($report, $found['results']),
        ];
    }

    /**
     * One contact, from the link on their address on their page, as a
     * search shows each contact it finds.
     *
     * @param  array{contact: Contact, address: ?ContactDetail, sites: Collection<int, array{contact: Contact, miles: float}>}  $result
     * @return array<string, mixed>
     */
    private function contactPage(MapReport $report, array $result): array
    {
        return [
            'summary' => $result['address']?->location !== null
                ? "Where {$result['contact']->name} is, with the drop-off sites."
                : "{$result['contact']->name}’s address isn’t on the map yet.",
            ...$this->resultsPage($report, collect([$result])),
        ];
    }

    /**
     * Contacts found by a search or opened from their address: each on the
     * map with the drop-off sites, and listed with their address, main
     * phone number, and the drop-off sites nearest first.
     *
     * @param  Collection<int, array{contact: Contact, address: ?ContactDetail, sites: Collection<int, array{contact: Contact, miles: float}>}>  $results
     * @return array{points: list<array<string, mixed>>, results: list<array<string, mixed>>}
     */
    private function resultsPage(MapReport $report, Collection $results): array
    {
        return [
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
                    $result['contact']->details->firstWhere('type', ContactDetail::TYPE_PHONE)?->displayValue(),
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
     * $extra adds what the script needs for trailer loads: a pickup's
     * application `id` while building a load, `ringed` for a pickup in a
     * planned load, or a stop's `number`.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function point(ContactDetail $address, string $name, string $kind, string $note, int $contactId, array $extra = []): array
    {
        return [
            'lat' => $address->location['latitude'],
            'lng' => $address->location['longitude'],
            'kind' => $kind,
            'name' => $name,
            'note' => $note,
            'url' => route('admin.contacts.show', $contactId),
            ...$extra,
        ];
    }

    /**
     * A pickup for the list: its name, with how soon as a label, when the
     * bags are ready, bags, the running total, the address (noting when
     * it isn't on the map yet), and a phone number. It links to the
     * contact, or to the application before it's linked to one.
     *
     * @param  array{application: LeafApplication, contact: ?Contact, address: ?ContactDetail, kind: string, days: ?int}  $pickup
     * @return array<string, mixed>
     */
    private function pickupRow(array $pickup, int $total): array
    {
        $application = $pickup['application'];
        $contact = $pickup['contact'];
        $address = $pickup['address']?->value ?? $application->address;
        $phone = ContactDetail::formatPhone($contact?->details()->current()->where('type', ContactDetail::TYPE_PHONE)->orderByDesc('is_primary')->value('value') ?? $application->phone);
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
     * A load's stop, for its list on wider screens and its card on phones:
     * the stop number, the name, linking to the contact (or the application
     * before it's linked to one), bags, the address (noting when it isn't on
     * the map yet), the main phone number with a link that dials it, and the
     * contact's notes, which can hold things like a gate code.
     *
     * @param  array{application: LeafApplication, contact: ?Contact, address: ?ContactDetail, position: int}  $stop
     * @return array{application: int, position: int, name: string, url: string, bags: int, address: string, phone: ?string, tel: ?string, notes: string}
     */
    private function stop(array $stop): array
    {
        $application = $stop['application'];
        $contact = $stop['contact'];
        $address = $stop['address']?->value ?? $application->address;
        $phone = $contact?->details->firstWhere('type', ContactDetail::TYPE_PHONE)?->value ?? $application->phone;
        $digits = $phone !== null ? ContactDetail::normalize(ContactDetail::TYPE_PHONE, $phone) : null;

        return [
            'application' => $application->id,
            'position' => $stop['position'],
            'name' => $contact?->name ?? $application->name,
            'url' => $contact ? route('admin.contacts.show', $contact->id) : route('admin.leaves.show', $application->id),
            'bags' => $application->estimated_bag_count,
            'address' => $stop['address']?->location !== null ? $address : "{$address} (not on the map yet)",
            'phone' => ContactDetail::formatPhone($phone),
            'tel' => $digits !== null ? "tel:+1{$digits}" : null,
            'notes' => trim((string) $contact?->notes),
        ];
    }

    /**
     * The applications chosen for a new or edited load in a form sent back
     * with errors, in order.
     *
     * @return list<int>
     */
    private function chosenStops(Request $request): array
    {
        $old = $request->old('stops', []);

        return is_array($old) ? array_values(array_unique(array_map('intval', array_filter($old, fn ($id) => is_scalar($id) && ctype_digit((string) $id))))) : [];
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
