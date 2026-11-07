<?php

namespace Tests\Feature;

use App\Services\LocationImport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * addresses:geocode, which looks up addresses copied from the Map page as
 * JSON in the local geocoder, with the geocoder faked. Made-up addresses
 * only.
 */
class GeocodeAddressesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->file = tempnam(sys_get_temp_dir(), 'addresses');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /**
     * The JSON the Map page's Copy box holds, for these addresses by ID.
     *
     * @param  array<int, string>  $addresses
     */
    private function copied(array $addresses): string
    {
        return LocationImport::encode([LocationImport::ADDRESSES => array_map(
            fn (int $id, string $address) => ['id' => $id, 'address' => $address],
            array_keys($addresses),
            $addresses,
        )]);
    }

    /**
     * A result as Nominatim's jsonv2 format gives it, with the coordinates
     * as strings.
     *
     * @return list<array<string, mixed>>
     */
    private function nominatimResult(string $lat, string $lon, int $rank): array
    {
        return [['lat' => $lat, 'lon' => $lon, 'place_rank' => $rank, 'category' => 'place', 'type' => 'house']];
    }

    public function test_prints_houses_and_lists_the_rest(): void
    {
        // Read only until the JSON is complete, as when it's pasted and Enter is pressed
        file_put_contents($this->file, $this->copied([
            41 => '412 Sample Ave, Fort Collins, CO 80521',
            57 => '9 Placeholder Rd, Fort Collins, CO 80525',
            63 => '1 Example Way, Loveland, CO',
            70 => 'Nowhere',
        ]) . "\nNot read");

        Http::fake(function (Request $request) {
            return match (true) {
                // Free-form searches, with the full address
                ($request['q'] ?? null) === '412 Sample Ave, Fort Collins, CO 80521' => Http::response($this->nominatimResult('40.5853', '-105.0844', 30)),
                ($request['q'] ?? null) === '9 Placeholder Rd, Fort Collins, CO 80525' => Http::response($this->nominatimResult('40.5530', '-105.1000', 26)),
                ($request['q'] ?? null) === '1 Example Way, Loveland, CO' => Http::response($this->nominatimResult('40.3977', '-105.0750', 26)),
                // Structured searches, after a free-form one misses the house
                ($request['street'] ?? null) === '9 Placeholder Rd' => Http::response($this->nominatimResult('40.5531', '-105.1010', 30)),
                default => Http::response([]),
            };
        });

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain('Found 2 of 4 addresses at a house. The Map page lists the rest to look up by hand.')
            ->expectsOutput(implode("\n", [
                '{"locations":[',
                '{"id":41,"latitude":40.5853,"longitude":-105.0844},',
                '{"id":57,"latitude":40.5531,"longitude":-105.101}',
                '],"street_only":[',
                '{"id":63,"latitude":40.3977,"longitude":-105.075}',
                '],"not_found":[',
                '{"id":70}',
                ']}',
            ]))
            // Only IDs and coordinates, never an address
            ->doesntExpectOutputToContain('Sample Ave')
            ->doesntExpectOutputToContain('Nowhere')
            ->assertSuccessful();

        // Checked against every request, so a key one doesn't have is read with ??
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://geocoder:8080/search?')
            && ($request['q'] ?? null) === '412 Sample Ave, Fort Collins, CO 80521'
            && $request['format'] === 'jsonv2'
            && (string) $request['limit'] === '1'
            && $request['countrycodes'] === 'us');
        // The structured search splits the ZIP code from the state
        Http::assertSent(fn (Request $request) => ($request['street'] ?? null) === '9 Placeholder Rd'
            && $request['city'] === 'Fort Collins'
            && $request['state'] === 'CO'
            && ($request['postalcode'] ?? null) === '80525');
        // Without a ZIP code, the structured search has none
        Http::assertSent(fn (Request $request) => ($request['street'] ?? null) === '1 Example Way'
            && $request['state'] === 'CO'
            && ! isset($request['postalcode']));
    }

    public function test_tries_looser_searches_when_the_full_address_misses(): void
    {
        file_put_contents($this->file, $this->copied([
            // Unincorporated, with a Fort Collins mailing address
            77 => '77 Example Ct, Fort Collins, CO 80528',
            // A house the data doesn't have, on a street it does
            78 => '5 Sample Ln Apt 2, Fort Collins, CO 80528',
        ]));

        Http::fake(function (Request $request) {
            return match ($request['q'] ?? null) {
                '77 Example Ct, 80528' => Http::response($this->nominatimResult('40.4791', '-104.9862', 30)),
                'Sample Ln, 80528' => Http::response($this->nominatimResult('40.4802', '-104.9871', 26)),
                default => Http::response([]),
            };
        });

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain('Found 1 of 2 addresses at a house.')
            ->expectsOutput(implode("\n", [
                '{"locations":[',
                '{"id":77,"latitude":40.4791,"longitude":-104.9862}',
                '],"street_only":[',
                '{"id":78,"latitude":40.4802,"longitude":-104.9871}',
                '],"not_found":[]}',
            ]))
            ->assertSuccessful();

        // The structured search leaves out the unit
        Http::assertSent(fn (Request $request) => ($request['street'] ?? null) === '5 Sample Ln'
            && $request['city'] === 'Fort Collins'
            && $request['postalcode'] === '80528');
        Http::assertSent(fn (Request $request) => ($request['q'] ?? null) === '5 Sample Ln, 80528');
        // The street alone only when not even the street was found
        Http::assertNotSent(fn (Request $request) => ($request['q'] ?? null) === 'Example Ct, 80528');
    }

    public function test_reads_json_laid_out_any_way(): void
    {
        file_put_contents($this->file, json_encode(['addresses' => [['id' => 41, 'address' => '412 Sample Ave, Fort Collins, CO']]], JSON_PRETTY_PRINT));
        Http::fake(['*' => Http::response($this->nominatimResult('40.5853', '-105.0844', 30))]);

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain('Found the address at a house.')
            ->expectsOutput("{\"locations\":[\n{\"id\":41,\"latitude\":40.5853,\"longitude\":-105.0844}\n],\"street_only\":[],\"not_found\":[]}")
            ->assertSuccessful();
    }

    public function test_says_what_to_paste_for_anything_else(): void
    {
        foreach (["41\t412 Sample Ave, Fort Collins, CO\n", '{"locations":[]}', '{"addresses":[{"id":"41"}]}', ''] as $text) {
            file_put_contents($this->file, $text);

            $this->artisan('addresses:geocode', ['file' => $this->file])
                ->expectsOutputToContain('Paste everything copied from the Map page')
                ->assertFailed()
                ->run();
        }

        Http::assertNothingSent();
    }

    public function test_says_when_the_geocoder_isnt_running(): void
    {
        file_put_contents($this->file, $this->copied([41 => '412 Sample Ave, Fort Collins, CO']));
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain("Can't reach the geocoder at http://geocoder:8080.")
            ->assertFailed();
    }

    public function test_runs_only_on_an_admins_computer(): void
    {
        file_put_contents($this->file, $this->copied([41 => '412 Sample Ave, Fort Collins, CO']));
        $this->app['env'] = 'production';

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain('Geocoding runs only on an admin’s computer')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
