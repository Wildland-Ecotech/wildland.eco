<?php

namespace App\Services;

use App\Models\ContactDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The JSON the admin Map page and `addresses:geocode` pass between them,
 * and the locations pasted back on the page.
 *
 * The page's Copy box holds the addresses without a location, such as
 * {"addresses":[{"id":42,"address":"123 Example St, Fort Collins, CO"}]}.
 * The command prints what it found: {"locations":[{"id":42,"latitude":
 * 40.58985,"longitude":-105.08191}],"street_only":[{"id":57,"latitude":…,
 * "longitude":…}],"not_found":[{"id":63}]}, by each address's ID. Both put
 * each entry on its own line (encode()), so no line is too long for a
 * terminal and the JSON can be read at a glance.
 *
 * preview() says what each entry pasted back would do; save() sets the
 * locations that can be saved, recording which admin set them. Only current
 * addresses of contacts not deleted are located, and only inside Colorado.
 * Addresses found only at the street, or not found, are listed to look up
 * by hand, and never saved.
 */
class LocationImport
{
    /**
     * The lists in the JSON: the addresses copied from the page, and what
     * the command found.
     */
    public const ADDRESSES = 'addresses';

    public const LOCATIONS = 'locations';

    public const STREET_ONLY = 'street_only';

    public const NOT_FOUND = 'not_found';

    /**
     * What an entry does.
     */
    public const SAVE = 'save';

    public const REPLACE = 'replace';

    public const UNKNOWN = 'unknown';

    public const CHANGED = 'changed';

    public const OUTSIDE = 'outside';

    public const INVALID = 'invalid';

    public const REPEATED = 'repeated';

    public const STREET = 'street';

    public const MISSED = 'missed';

    public const RESULT_LABELS = [
        self::SAVE => 'Will be saved',
        self::REPLACE => 'Will replace its location',
        self::UNKNOWN => 'No address with this number',
        self::CHANGED => 'Address changed since it was copied',
        self::OUTSIDE => 'Outside Colorado',
        self::INVALID => 'Missing its number or location',
        self::REPEATED => 'Listed again later',
        self::STREET => 'Only the street was found',
        self::MISSED => 'Not found by the geocoder',
    ];

    /**
     * Roughly Colorado's borders, with a little room.
     */
    public const SOUTH = 36.95;

    public const NORTH = 41.05;

    public const WEST = -109.1;

    public const EAST = -102.0;

    /**
     * JSON with each entry of each list on its own line, such as
     * {"locations":[\n{"id":42,…}\n],"not_found":[\n{"id":63}\n]}.
     *
     * @param  array<string, list<array<string, mixed>>>  $lists
     */
    public static function encode(array $lists): string
    {
        $parts = [];

        foreach ($lists as $key => $entries) {
            $encoded = array_map(fn (array $entry) => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $entries);
            $parts[] = json_encode($key) . ':[' . ($encoded === [] ? '' : "\n" . implode(",\n", $encoded) . "\n") . ']';
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * The lists in what `addresses:geocode` printed, each a list of
     * entries, or null when it isn't JSON with a list of locations.
     *
     * @return array{locations: list<mixed>, street_only: list<mixed>, not_found: list<mixed>}|null
     */
    public static function decode(string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_array($data[self::LOCATIONS] ?? null) || ! array_is_list($data[self::LOCATIONS])) {
            return null;
        }

        foreach ([self::STREET_ONLY, self::NOT_FOUND] as $key) {
            if (isset($data[$key]) && ! (is_array($data[$key]) && array_is_list($data[$key]))) {
                return null;
            }
        }

        return [
            self::LOCATIONS => $data[self::LOCATIONS],
            self::STREET_ONLY => $data[self::STREET_ONLY] ?? [],
            self::NOT_FOUND => $data[self::NOT_FOUND] ?? [],
        ];
    }

    /**
     * Each entry, in order: the locations, then those found only at the
     * street, then those not found. Each has the entry as pasted, the ID,
     * latitude, and longitude read from it (null when missing), the address
     * it names (null when there's none), and what it would do. Pasted JSON
     * that isn't from the command has no entries.
     *
     * @return list<array{entry: string, id: ?int, latitude: ?float, longitude: ?float, address: ?ContactDetail, result: string}>
     */
    public function preview(string $json): array
    {
        $lists = self::decode($json) ?? [self::LOCATIONS => [], self::STREET_ONLY => [], self::NOT_FOUND => []];

        $rows = array_map(fn (mixed $entry) => $this->row($entry, self::INVALID), $lists[self::LOCATIONS]);
        $others = [
            ...array_map(fn (mixed $entry) => $this->row($entry, self::STREET), $lists[self::STREET_ONLY]),
            ...array_map(fn (mixed $entry) => $this->row($entry, self::MISSED), $lists[self::NOT_FOUND]),
        ];

        $addresses = ContactDetail::query()
            ->with('contact')
            ->whereIn('id', array_values(array_filter(array_column([...$rows, ...$others], 'id'), fn ($id) => $id !== null)))
            ->where('type', ContactDetail::TYPE_ADDRESS)
            ->get()
            ->keyBy('id');

        // Locations: the last entry for an address is the one that counts
        $located = array_values(array_filter($rows, fn (array $row) => $row['id'] !== null && $row['latitude'] !== null));
        $lastFor = array_flip(array_column($located, 'id'));
        $index = 0;

        foreach ($rows as &$row) {
            if ($row['id'] === null || $row['latitude'] === null) {
                continue;
            }

            $address = $addresses->get($row['id']);
            $row['address'] = $address;
            $row['result'] = match (true) {
                $address === null || $address->contact === null || $address->contact->trashed() => self::UNKNOWN,
                $lastFor[$row['id']] !== $index => self::REPEATED,
                $address->isRemoved() => self::CHANGED,
                ! $this->inColorado($row['latitude'], $row['longitude']) => self::OUTSIDE,
                $address->location !== null => self::REPLACE,
                default => self::SAVE,
            };
            $index++;
        }
        unset($row);

        // Found only at the street, or not found: named, to look up by hand
        foreach ($others as &$row) {
            if ($row['id'] === null) {
                $row['result'] = self::INVALID;

                continue;
            }

            $address = $addresses->get($row['id']);
            $row['address'] = $address !== null && $address->contact !== null && ! $address->contact->trashed() ? $address : null;
        }
        unset($row);

        return [...$rows, ...$others];
    }

    /**
     * Set every location that can be saved, as the admin, and say how many.
     */
    public function save(string $json, User $admin): int
    {
        $rows = array_filter($this->preview($json), fn (array $row) => in_array($row['result'], [self::SAVE, self::REPLACE], true));

        DB::transaction(function () use ($rows, $admin) {
            foreach ($rows as $row) {
                $row['address']->locate($row['latitude'], $row['longitude'], $admin);
            }
        });

        return count($rows);
    }

    /**
     * An entry as pasted, with its ID, latitude, and longitude when they're
     * numbers, and what it does until its address is looked up.
     *
     * @return array{entry: string, id: ?int, latitude: ?float, longitude: ?float, address: ?ContactDetail, result: string}
     */
    private function row(mixed $entry, string $result): array
    {
        $values = is_array($entry) ? $entry : [];
        $latitude = $values['latitude'] ?? null;
        $longitude = $values['longitude'] ?? null;
        $located = (is_int($latitude) || is_float($latitude)) && (is_int($longitude) || is_float($longitude));

        return [
            'entry' => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'id' => is_int($values['id'] ?? null) && $values['id'] > 0 ? $values['id'] : null,
            'latitude' => $located ? (float) $latitude : null,
            'longitude' => $located ? (float) $longitude : null,
            'address' => null,
            'result' => $result,
        ];
    }

    private function inColorado(float $latitude, float $longitude): bool
    {
        return $latitude >= self::SOUTH && $latitude <= self::NORTH
            && $longitude >= self::WEST && $longitude <= self::EAST;
    }
}
