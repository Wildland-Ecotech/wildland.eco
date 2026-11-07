<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * addresses:geocode, which looks up addresses copied from the Map page in
 * the local geocoder, with the geocoder faked. Made-up addresses only.
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
        file_put_contents($this->file, implode("\n", [
            "41\t412 Sample Ave, Fort Collins, CO 80521",
            "57\t9 Placeholder Rd, Fort Collins, CO",
            "63\t1 Example Way, Loveland, CO",
            "70\tNowhere",
            '',
        ]));

        Http::fake(function (Request $request) {
            return match (true) {
                // Free-form searches, without the ZIP code
                ($request['q'] ?? null) === '412 Sample Ave, Fort Collins, CO' => Http::response($this->nominatimResult('40.5853', '-105.0844', 30)),
                ($request['q'] ?? null) === '9 Placeholder Rd, Fort Collins, CO' => Http::response($this->nominatimResult('40.5530', '-105.1000', 26)),
                ($request['q'] ?? null) === '1 Example Way, Loveland, CO' => Http::response($this->nominatimResult('40.3977', '-105.0750', 26)),
                // Structured searches, after a free-form one misses the house
                ($request['street'] ?? null) === '9 Placeholder Rd' => Http::response($this->nominatimResult('40.5531', '-105.1010', 30)),
                default => Http::response([]),
            };
        });

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutput("41\t40.5853,-105.0844")
            ->expectsOutput("57\t40.5531,-105.1010")
            ->expectsOutputToContain("# 63\t1 Example Way, Loveland, CO\t(the street: 40.3977,-105.0750)")
            ->expectsOutputToContain("# 70\tNowhere")
            ->doesntExpectOutputToContain('80521')
            ->assertSuccessful();

        // Checked against every request, so a key one doesn't have is read with ??
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://geocoder:8080/search?')
            && ($request['q'] ?? null) === '412 Sample Ave, Fort Collins, CO'
            && $request['format'] === 'jsonv2'
            && (string) $request['limit'] === '1'
            && $request['countrycodes'] === 'us');
        Http::assertSent(fn (Request $request) => ($request['street'] ?? null) === '9 Placeholder Rd'
            && $request['city'] === 'Fort Collins'
            && $request['state'] === 'CO'
            && ! isset($request['postalcode']));
        // No ZIP code is ever sent
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '80521'));
    }

    public function test_says_when_the_geocoder_isnt_running(): void
    {
        file_put_contents($this->file, "41\t412 Sample Ave, Fort Collins, CO\n");
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain("Can't reach the geocoder at http://geocoder:8080.")
            ->assertFailed();
    }

    public function test_runs_only_on_an_admins_computer(): void
    {
        file_put_contents($this->file, "41\t412 Sample Ave, Fort Collins, CO\n");
        $this->app['env'] = 'production';

        $this->artisan('addresses:geocode', ['file' => $this->file])
            ->expectsOutputToContain('Geocoding runs only on an admin’s computer')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
