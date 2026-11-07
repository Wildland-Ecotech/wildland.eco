<?php

namespace App\Console\Commands;

use App\Services\LocationImport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Looks up addresses copied from the admin Map page in the local geocoder
 * (Nominatim, docs/07-deployment.md "Geocoder"), and prints their
 * locations to paste back on the page. It runs only on an admin's computer,
 * so no address leaves it: the servers never call a geocoder.
 *
 * Run it, paste the JSON from the page's Copy box, and press Enter: it
 * reads a line at a time and starts as soon as the JSON is complete, so the
 * addresses never go into a file. A file or a pipe works too. The JSON and
 * what it prints are described in LocationImport. For each address, it
 * tries the full address as typed, then looser searches until one finds
 * the house (lookUp()), such as without the city for an unincorporated
 * address with a Fort Collins mailing address. Only a match at a house
 * (place_rank 30) is a location; matches at only the street, and
 * addresses it couldn't place, are listed separately, for the Map page to
 * show to look up by hand. It prints only IDs and coordinates, but they
 * place people's homes: never paste them into issues, chat, or the
 * repository.
 */
class GeocodeAddresses extends Command
{
    /**
     * @var string
     */
    protected $signature = 'addresses:geocode
        {file? : A file of the JSON copied from the Map page; without one, paste it and press Enter}
        {--url=http://geocoder:8080 : The local geocoder}';

    /**
     * @var string
     */
    protected $description = 'Look up addresses from the Map page in the local geocoder';

    /**
     * Nominatim's place_rank for a house; streets are 26 and 27.
     */
    public const HOUSE_RANK = 30;

    public const STREET_RANK = 26;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Geocoding runs only on an admin’s computer, so no address leaves it.');

            return self::FAILURE;
        }

        $file = $this->argument('file');
        $errors = $this->output->getErrorStyle();
        $stream = $file === null ? STDIN : @fopen($file, 'r');

        if ($stream === false) {
            $this->components->error("Can't read {$file}.");

            return self::FAILURE;
        }

        if ($file === null && stream_isatty(STDIN)) {
            $errors->writeln('Paste the addresses copied from the Map page, then press Enter:');
        }

        $addresses = $this->readAddresses($stream);

        if ($file !== null) {
            fclose($stream);
        }

        if ($addresses === null) {
            $this->components->error('Paste everything copied from the Map page, from the { at the start to the } at the end.');

            return self::FAILURE;
        }

        $found = [LocationImport::LOCATIONS => [], LocationImport::STREET_ONLY => [], LocationImport::NOT_FOUND => []];

        foreach ($addresses as $entry) {
            $address = trim($entry['address']);

            try {
                $result = $this->lookUp($address);
            } catch (ConnectionException) {
                $this->components->error("Can't reach the geocoder at {$this->option('url')}. Start it with: docker compose --profile geocoder up -d geocoder");

                return self::FAILURE;
            }

            $list = match (true) {
                $result !== null && $result['place_rank'] >= self::HOUSE_RANK => LocationImport::LOCATIONS,
                $result !== null && $result['place_rank'] >= self::STREET_RANK => LocationImport::STREET_ONLY,
                default => LocationImport::NOT_FOUND,
            };

            $found[$list][] = $list === LocationImport::NOT_FOUND
                ? ['id' => $entry['id']]
                : ['id' => $entry['id'], 'latitude' => (float) $result['lat'], 'longitude' => (float) $result['lon']];
        }

        // To standard error, so redirecting the output to a file keeps only
        // the JSON, with a blank line on each side so it's easy to select
        $errors->writeln(['', $this->summary(count($found[LocationImport::LOCATIONS]), count($addresses)), 'Copy everything below, from { to }, and paste it on the Map page:', '']);
        $this->line(LocationImport::encode($found));
        $errors->writeln('');

        return self::SUCCESS;
    }

    /**
     * How many addresses were found at a house, such as "Found 3 of 5
     * addresses at a house."
     */
    private function summary(int $houses, int $total): string
    {
        return match (true) {
            $total === 0 => 'There were no addresses to look up.',
            $houses === $total => $total === 1 ? 'Found the address at a house.' : "Found all {$total} addresses at a house.",
            default => "Found {$houses} of {$total} " . ($total === 1 ? 'address' : 'addresses') . ' at a house. The Map page lists the rest to look up by hand.',
        };
    }

    /**
     * The addresses pasted, or read from a file or a pipe, a line at a
     * time until the JSON is complete, so pressing Enter after pasting is
     * enough. Null when what was read isn't the Map page's addresses, which
     * shows at the first character that isn't a space.
     *
     * @param  resource  $stream
     * @return list<array{id: int, address: string}>|null
     */
    private function readAddresses($stream): ?array
    {
        $text = '';

        while (($line = fgets($stream)) !== false) {
            $text .= $line;
            $start = ltrim($text);

            if ($start !== '' && $start[0] !== '{') {
                return null;
            }

            if ($start !== '' && json_validate($text)) {
                break;
            }
        }

        $data = json_decode($text, true);
        $entries = is_array($data) ? ($data[LocationImport::ADDRESSES] ?? null) : null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_int($entry['id'] ?? null) || ! is_string($entry['address'] ?? null)) {
                return null;
            }
        }

        return $entries;
    }

    /**
     * The best match for an address, from looser searches in turn, stopping
     * at the first that finds the house:
     *
     * 1. The full address, as typed.
     * 2. Its street, city, state, and ZIP code as separate fields.
     * 3. The street and ZIP code, without the city, for an address whose
     *    mailing city isn't the place it's in, such as unincorporated
     *    Larimer County with a Fort Collins address.
     * 4. Only when not even the street was found: the street's name without
     *    the house number, with the ZIP code (or the city and state), so an
     *    address whose house isn't in the data still gets its street's
     *    location to check.
     *
     * Units, such as "Apt 2", are left out after the first search. The
     * highest-ranked match counts, the earliest on a tie. Null when nothing
     * is found.
     *
     * @return array{lat: string, lon: string, place_rank: int}|null
     */
    private function lookUp(string $address): ?array
    {
        $parts = self::parts($address);
        $queries = [['q' => $address]];

        if ($parts['street'] !== '' && ($parts['city'] !== null || $parts['zip'] !== null)) {
            $queries[] = array_filter(
                ['street' => $parts['street'], 'city' => $parts['city'], 'state' => $parts['state'], 'postalcode' => $parts['zip']],
                fn (?string $value) => $value !== null,
            );
        }

        if ($parts['street'] !== '' && $parts['zip'] !== null) {
            $queries[] = ['q' => "{$parts['street']}, {$parts['zip']}"];
        }

        $best = null;

        foreach (array_unique($queries, SORT_REGULAR) as $query) {
            $best = $this->better($best, $this->search($query));

            if ($best !== null && $best['place_rank'] >= self::HOUSE_RANK) {
                return $best;
            }
        }

        if (($best === null || $best['place_rank'] < self::STREET_RANK) && $parts['road'] !== '' && ($parts['zip'] !== null || $parts['city'] !== null)) {
            $where = $parts['zip'] ?? implode(', ', array_filter([$parts['city'], $parts['state']]));
            $best = $this->better($best, $this->search(['q' => "{$parts['road']}, {$where}"]));
        }

        return $best;
    }

    /**
     * The higher-ranked of two matches, the first on a tie.
     *
     * @param  array{lat: string, lon: string, place_rank: int}|null  $best
     * @param  array{lat: string, lon: string, place_rank: int}|null  $result
     * @return array{lat: string, lon: string, place_rank: int}|null
     */
    private function better(?array $best, ?array $result): ?array
    {
        return $result !== null && ($best === null || $result['place_rank'] > $best['place_rank']) ? $result : $best;
    }

    /**
     * An address's parts, as in "123 Example St Apt 2, Fort Collins, CO
     * 80521": the street with its house number but without a unit, the
     * street's name alone (`road`), and the city, state, and ZIP code, or
     * null for those it doesn't have. Parts between the street and the
     * city, such as a unit of their own, are left out.
     *
     * @return array{street: string, road: string, city: ?string, state: ?string, zip: ?string}
     */
    private static function parts(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn (string $part) => $part !== ''));
        $zip = null;
        $state = null;

        // A ZIP code at the end, after the state or as a part of its own
        if ($parts !== [] && preg_match('/^(.*?)\s*(\d{5}(?:-\d{4})?)$/', end($parts), $matches)) {
            $zip = $matches[2];
            array_pop($parts);

            if (trim($matches[1]) !== '') {
                $parts[] = trim($matches[1]);
            }
        }

        // The state: two letters, such as CO, or Colorado
        if (count($parts) > 1 && preg_match('/^(?:[A-Za-z]{2}|Colorado)$/i', end($parts))) {
            $state = array_pop($parts);
        }

        $street = trim(preg_replace('/\s*(?:#|\b(?:apt|apartment|unit|ste|suite)\b\.?)\s*[\w-]+$/i', '', $parts[0] ?? ''));

        return [
            'street' => $street,
            'road' => trim(preg_replace('/^\d+[A-Za-z]?(?:-\d+)?\s+/', '', $street)),
            'city' => count($parts) > 1 ? end($parts) : null,
            'state' => $state,
            'zip' => $zip,
        ];
    }

    /**
     * The first result of a search in the United States, or null.
     *
     * @param  array<string, string>  $query
     * @return array{lat: string, lon: string, place_rank: int}|null
     */
    private function search(array $query): ?array
    {
        $response = Http::timeout(15)->get(rtrim($this->option('url'), '/') . '/search', [
            ...$query,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => 'us',
        ]);
        $first = $response->successful() ? ($response->json()[0] ?? null) : null;

        return is_array($first) && isset($first['lat'], $first['lon'], $first['place_rank'])
            ? ['lat' => (string) $first['lat'], 'lon' => (string) $first['lon'], 'place_rank' => (int) $first['place_rank']]
            : null;
    }
}
