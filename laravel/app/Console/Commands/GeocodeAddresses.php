<?php

namespace App\Console\Commands;

use App\Reports\MapReport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Looks up addresses copied from the admin Map page in the local geocoder
 * (Nominatim, docs/07-deployment.md "Geocoder"), and prints their
 * locations to paste back on the page. It runs only on an admin's computer,
 * so no address leaves it: the servers never call a geocoder.
 *
 * Run it and paste the lines, then press Enter and Ctrl+D, so the addresses
 * never go into a file; or give it a file, or pipe them in. Each line read is
 * an address's ID, a tab, and the address. For each, it
 * tries the address as typed, then, if that misses, its street, city, and
 * state as separate fields. ZIP codes are left out, since Nominatim matches
 * Fort Collins addresses better without them. Only a match at a house
 * (place_rank 30) is printed, as "42	40.5898512,-105.0819134"; matches at
 * only the street, and addresses it couldn't place, are listed after, to
 * look up by hand. Its output names nobody, but the addresses are personal:
 * never paste them into issues, chat, or the repository.
 */
class GeocodeAddresses extends Command
{
    /**
     * @var string
     */
    protected $signature = 'addresses:geocode
        {file? : A file of lines copied from the Map page; without one, paste them and press Enter and Ctrl+D}
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
        $typed = $file === null && stream_isatty(STDIN);

        if ($typed) {
            $errors->writeln('Paste the lines from the Map page, then press Enter and Ctrl+D:');
        }

        $text = $file === null ? stream_get_contents(STDIN) : @file_get_contents($file);

        if ($text === false) {
            $this->components->error("Can't read {$file}.");

            return self::FAILURE;
        }

        $streetOnly = [];
        $unplaced = [];

        if ($typed) {
            $errors->writeln(['', '# Copy everything from here to the end, and paste it on the Map page:']);
        }

        foreach (preg_split('/\R/', $text) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (! preg_match('/^\s*(\d+)\s+(.+?)\s*$/', $line, $matches)) {
                $unplaced[] = trim($line) . ' (not an address’s number and an address)';

                continue;
            }

            [, $id, $address] = $matches;
            $address = MapReport::withoutZip($address);

            try {
                $result = $this->lookUp($address);
            } catch (ConnectionException) {
                $this->components->error("Can't reach the geocoder at {$this->option('url')}. Start it with: docker compose --profile geocoder up -d geocoder");

                return self::FAILURE;
            }

            if ($result !== null && $result['place_rank'] >= self::HOUSE_RANK) {
                $this->line("{$id}\t{$result['lat']},{$result['lon']}");
            } elseif ($result !== null && $result['place_rank'] >= self::STREET_RANK) {
                $streetOnly[] = "{$id}\t{$address}\t(the street: {$result['lat']},{$result['lon']})";
            } else {
                $unplaced[] = "{$id}\t{$address}";
            }
        }

        // After the locations, and to standard error, so redirecting the
        // output to a file keeps only lines to paste
        if ($streetOnly !== []) {
            $errors->writeln(['', '# Only the street was found for these, so they were left out. Check them on a map:', ...array_map(fn ($line) => "# {$line}", $streetOnly)]);
        }

        if ($unplaced !== []) {
            $errors->writeln(['', '# Couldn’t place these. Check the address, or look them up by hand:', ...array_map(fn ($line) => "# {$line}", $unplaced)]);
        }

        return self::SUCCESS;
    }

    /**
     * The best match for an address: the free-form search's, if it's a
     * house, or else the structured search's when it ranks higher. Null
     * when neither finds anything.
     *
     * @return array{lat: string, lon: string, place_rank: int}|null
     */
    private function lookUp(string $address): ?array
    {
        $best = $this->search(['q' => $address]);

        if ($best !== null && $best['place_rank'] >= self::HOUSE_RANK) {
            return $best;
        }

        // "123 Example St, Fort Collins, CO" as its street, city, and state
        $parts = array_map('trim', explode(',', $address));

        if (count($parts) >= 3) {
            $structured = $this->search(['street' => $parts[0], 'city' => $parts[1], 'state' => $parts[2]]);

            if ($structured !== null && ($best === null || $structured['place_rank'] > $best['place_rank'])) {
                return $structured;
            }
        }

        return $best;
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
