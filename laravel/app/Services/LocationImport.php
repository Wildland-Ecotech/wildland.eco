<?php

namespace App\Services;

use App\Models\ContactDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Locations pasted on the admin Map page, as `addresses:geocode` prints
 * them: one per line, an address's ID, a tab or spaces, and a latitude and
 * longitude separated by a comma, such as "42	40.58985,-105.08191". Lines
 * starting with # are left out. preview() says what each line would do;
 * save() sets the locations that can be saved, recording which admin set
 * them. Only current addresses of contacts not deleted are located, and only
 * inside Colorado.
 */
class LocationImport
{
    /**
     * What a line does.
     */
    public const SAVE = 'save';

    public const REPLACE = 'replace';

    public const UNKNOWN = 'unknown';

    public const CHANGED = 'changed';

    public const OUTSIDE = 'outside';

    public const INVALID = 'invalid';

    public const REPEATED = 'repeated';

    public const RESULT_LABELS = [
        self::SAVE => 'Will be saved',
        self::REPLACE => 'Will replace its location',
        self::UNKNOWN => 'No address with this number',
        self::CHANGED => 'Address changed since it was copied',
        self::OUTSIDE => 'Outside Colorado',
        self::INVALID => 'Not a number and a location',
        self::REPEATED => 'A later line has this number',
    ];

    /**
     * Roughly Colorado's borders, with a little room.
     */
    public const SOUTH = 36.95;

    public const NORTH = 41.05;

    public const WEST = -109.1;

    public const EAST = -102.0;

    /**
     * Each line, in order, with the ID, latitude, and longitude read from
     * it (null when it couldn't be read), the address it names (null when
     * there's none), and what it would do.
     *
     * @return list<array{line: string, id: ?int, latitude: ?float, longitude: ?float, address: ?ContactDetail, result: string}>
     */
    public function preview(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            preg_match('/^(\d+)\s+(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $line, $matches);
            $rows[] = [
                'line' => $line,
                'id' => isset($matches[1]) ? (int) $matches[1] : null,
                'latitude' => isset($matches[2]) ? (float) $matches[2] : null,
                'longitude' => isset($matches[3]) ? (float) $matches[3] : null,
                'address' => null,
                'result' => self::INVALID,
            ];
        }

        $ids = array_values(array_filter(array_column($rows, 'id'), fn ($id) => $id !== null));
        $addresses = ContactDetail::query()
            ->with('contact')
            ->whereIn('id', $ids)
            ->where('type', ContactDetail::TYPE_ADDRESS)
            ->get()
            ->keyBy('id');
        $lastLineFor = array_flip($ids);
        $index = 0;

        foreach ($rows as &$row) {
            if ($row['id'] === null) {
                continue;
            }

            $address = $addresses->get($row['id']);
            $row['address'] = $address;
            $row['result'] = match (true) {
                $address === null || $address->contact === null || $address->contact->trashed() => self::UNKNOWN,
                $lastLineFor[$row['id']] !== $index => self::REPEATED,
                $address->isRemoved() => self::CHANGED,
                ! $this->inColorado($row['latitude'], $row['longitude']) => self::OUTSIDE,
                $address->location !== null => self::REPLACE,
                default => self::SAVE,
            };
            $index++;
        }
        unset($row);

        return $rows;
    }

    /**
     * Set every location that can be saved, as the admin, and say how many.
     */
    public function save(string $text, User $admin): int
    {
        $rows = array_filter($this->preview($text), fn (array $row) => in_array($row['result'], [self::SAVE, self::REPLACE], true));

        DB::transaction(function () use ($rows, $admin) {
            foreach ($rows as $row) {
                $row['address']->locate($row['latitude'], $row['longitude'], $admin);
            }
        });

        return count($rows);
    }

    private function inColorado(float $latitude, float $longitude): bool
    {
        return $latitude >= self::SOUTH && $latitude <= self::NORTH
            && $longitude >= self::WEST && $longitude <= self::EAST;
    }
}
