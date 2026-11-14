<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use App\Services\LocationImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Map page (/admin/map): pending pickups colored by when the bags
 * are ready, everything located, a search with the closest drop-off sites,
 * and copying addresses as JSON and pasting their locations back.
 */
class AdminMapTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    /**
     * A contact with an address, located when given a latitude and
     * longitude.
     */
    private function contact(string $name, string $address, ?float $latitude = null, ?float $longitude = null, bool $site = false): Contact
    {
        $contact = Contact::create(['name' => $name, 'is_drop_off_site' => $site]);
        $detail = $contact->moveTo($address);

        if ($latitude !== null) {
            $detail->locate($latitude, $longitude, $this->admin);
        }

        return $contact;
    }

    /**
     * The contact's current address.
     */
    private function address(Contact $contact): ContactDetail
    {
        return $contact->details()->current()->where('type', ContactDetail::TYPE_ADDRESS)->sole();
    }

    /**
     * A pending application of the contact's, made $daysAgo.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(Contact $contact, int $daysAgo = 3, array $attributes = []): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $contact->name,
            'email' => 'applicant@example.com',
            'phone' => '(970) 555-0100',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now()->subDays($daysAgo),
            'contact_id' => $contact->id,
            'created_at' => now()->subDays($daysAgo),
            ...$attributes,
        ])->save();

        return $application;
    }

    /**
     * The map's points from the page, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function points($response): array
    {
        preg_match('#<script type="application/json" id="map-data">(.*?)</script>#s', $response->getContent(), $matches);

        return collect(json_decode($matches[1], true))->keyBy('name')->all();
    }

    public function test_only_signed_in_admins_can_see_it(): void
    {
        $this->get('/admin/map')->assertRedirect(route('login'));
        $this->post('/admin/map/locations', ['results' => '{"locations":[]}'])->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->get('/admin/map')->assertForbidden();
        $this->actingAs($donor)->post('/admin/map/locations', ['results' => '{"locations":[]}'])->assertForbidden();
    }

    public function test_the_map_follows_contacts_in_the_admin_links(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSeeInOrder(['>Contacts</a>', '>Map</a>', '>Leaves</a>'], false);
    }

    public function test_needs_scheduling_lists_pickups_soonest_first_with_a_running_total(): void
    {
        $sam = $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);
        $this->application($sam, attributes: ['estimated_bag_count' => 40]);
        $chris = $this->contact('Chris Park', '9 Placeholder Rd, Fort Collins, CO 80525', 40.553, -105.101);
        $this->application($chris, attributes: ['ready_now' => false, 'ready_date' => '2026-11-10', 'estimated_bag_count' => 60]);
        $robin = $this->contact('Robin Fox', '7 Sample Ln, Fort Collins, CO 80526', 40.5961, -105.1205);
        $this->application($robin, attributes: ['ready_now' => false, 'ready_date' => '2026-11-18', 'estimated_bag_count' => 35]);
        // Not located yet
        $dana = $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');
        $this->application($dana, attributes: ['ready_now' => false, 'ready_date' => '2026-12-05', 'estimated_bag_count' => 50]);
        // Left out: a drop-off, and a pickup that's done
        $alex = $this->contact('Alex Kim', '88 Example Ct, Fort Collins, CO 80524', 40.5788, -105.0255);
        $this->application($alex, attributes: ['type' => LeafApplication::TYPE_DROP_OFF]);
        $pat = $this->contact('Pat Morgan', '5 Sample Dr, Fort Collins, CO 80521', 40.57, -105.07);
        $this->application($pat, attributes: ['status' => LeafApplication::STATUS_COMPLETE]);
        $this->contact('North Drop-off Site', '1 Example Way, Fort Collins, CO 80524', 40.565, -105.09, site: true);

        $response = $this->actingAs($this->admin)->get('/admin/map');

        $response->assertOk()
            ->assertSee('<option value="pickups" selected>Needs scheduling</option>', false)
            ->assertSee('4 pickups need scheduling, 185 bags in all: 3 on the map, 1 still needs a location.')
            ->assertSeeInOrder([
                '<summary>Sam Lee · Now · 40 bags <span class="badge urgent">Ready now</span></summary>',
                '<summary>Chris Park · Nov 10 · 60 bags <span class="badge urgent">This week</span></summary>',
                '<summary>Robin Fox · Nov 18 · 35 bags <span class="badge soon">In 1 to 2 weeks</span></summary>',
                '<summary>Dana Cruz · Dec 5 · 50 bags <span class="badge">In 2+ weeks</span></summary>',
            ], false)
            // The running total of bags
            ->assertSeeInOrder(['<td class="numeric">100</td>', '<td class="numeric">135</td>', '<td class="numeric">185</td>'], false)
            ->assertSee('3 Example Blvd, Fort Collins, CO 80525 (not on the map yet)')
            ->assertSeeInOrder(['Ready now or within a week (2)', 'Ready in 1 to 2 weeks (1)', 'Drop-off site (1)'])
            ->assertDontSee('Alex Kim')
            ->assertDontSee('Pat Morgan');

        $points = $this->points($response);
        $this->assertSame(['Sam Lee', 'Chris Park', 'Robin Fox', 'North Drop-off Site'], array_keys($points));
        $this->assertSame(['now', 'now', 'soon', 'site'], array_column($points, 'kind'));
        $this->assertSame('Ready now · 40 bags', $points['Sam Lee']['note']);
        $this->assertSame('Ready Nov 10 · 60 bags', $points['Chris Park']['note']);
        $this->assertEqualsWithDelta(40.5853, $points['Sam Lee']['lat'], 1e-9);
        $this->assertEqualsWithDelta(-105.0844, $points['Sam Lee']['lng'], 1e-9);
        $this->assertSame(route('admin.contacts.show', $sam->id), $points['Sam Lee']['url']);
    }

    public function test_says_when_nobody_needs_a_pickup(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertOk()
            ->assertSee('Nobody is waiting for a pickup.')
            ->assertDontSee('<h2>Pickups</h2>', false);
    }

    public function test_everything_shows_every_located_contact(): void
    {
        $sam = $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);
        $this->application($sam);
        $alex = $this->contact('Alex Kim', '88 Example Ct, Fort Collins, CO 80524', 40.5788, -105.0255);
        $this->application($alex, attributes: ['type' => LeafApplication::TYPE_DROP_OFF]);
        $this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525', 40.56, -105.06);
        $this->contact('North Drop-off Site', '1 Example Way, Fort Collins, CO 80524', 40.565, -105.09, site: true);
        // Not located yet
        $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');

        $response = $this->actingAs($this->admin)->get('/admin/map?show=everything');

        $response->assertSee('<option value="everything" selected>Everything</option>', false)
            ->assertSee('4 places on the map: 1 pickup waiting, 2 other contacts, and 1 drop-off site.')
            ->assertDontSee('<h2>Pickups</h2>', false);

        $points = $this->points($response);
        $this->assertSame(['now', 'other', 'other', 'site'], array_column($points, 'kind'));
        $this->assertArrayNotHasKey('Dana Cruz', $points);
    }

    public function test_search_shows_the_closest_drop_off_sites(): void
    {
        $jamie = $this->contact('Jamie Rivera', '123 Example St, Fort Collins, CO 80521', 40.58, -105.08);
        $jamie->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $this->contact('South Drop-off Site', '2 Example Way, Fort Collins, CO 80525', 40.53, -105.08, site: true);
        $this->contact('North Drop-off Site', '1 Example Way, Fort Collins, CO 80524', 40.59, -105.08, site: true);
        $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);

        $response = $this->actingAs($this->admin)->get('/admin/map?q=rivera');

        $response->assertSee('1 contact matching “rivera”.', false)
            ->assertSeeInOrder([
                '<h2>Jamie Rivera</h2>',
                '123 Example St, Fort Collins, CO 80521 · 970-555-0123',
                'View contact',
                'Closest drop-off site', 'North Drop-off Site · 0.7 mi',
                'Next closest', 'South Drop-off Site · 3.5 mi',
            ], false)
            ->assertDontSee('Sam Lee');

        $points = $this->points($response);
        // The sites by name
        $this->assertSame(['Jamie Rivera', 'North Drop-off Site', 'South Drop-off Site'], array_keys($points));
        $this->assertSame('other', $points['Jamie Rivera']['kind']);
    }

    public function test_search_says_when_there_is_no_location_or_no_match(): void
    {
        $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');

        $this->actingAs($this->admin)
            ->get('/admin/map?q=dana')
            ->assertSee('No location yet, so the distances aren’t known.', false);

        $this->actingAs($this->admin)
            ->get('/admin/map?q=nobody')
            ->assertSee('No contacts match “nobody”.', false);
    }

    public function test_a_contacts_address_opens_them_with_the_closest_drop_off_sites(): void
    {
        $jamie = $this->contact('Jamie Rivera', '123 Example St, Fort Collins, CO 80521', 40.58, -105.08);
        $jamie->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0123');
        $this->contact('South Drop-off Site', '2 Example Way, Fort Collins, CO 80525', 40.53, -105.08, site: true);
        $this->contact('North Drop-off Site', '1 Example Way, Fort Collins, CO 80524', 40.59, -105.08, site: true);
        // Someone with the same last name isn't shown, as a search would
        $this->contact('Alex Rivera', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);

        $response = $this->actingAs($this->admin)->get("/admin/map?contact={$jamie->id}");

        $response->assertOk()
            ->assertSee('Where Jamie Rivera is, with the drop-off sites.')
            ->assertSeeInOrder([
                '<h2>Jamie Rivera</h2>',
                '123 Example St, Fort Collins, CO 80521 · 970-555-0123',
                'View contact',
                'Closest drop-off site', 'North Drop-off Site · 0.7 mi',
                'Next closest', 'South Drop-off Site · 3.5 mi',
            ], false)
            ->assertDontSee('Alex Rivera')
            ->assertDontSee('Start a Trailer Load');

        $this->assertSame(['Jamie Rivera', 'North Drop-off Site', 'South Drop-off Site'], array_keys($this->points($response)));
    }

    public function test_a_contact_without_a_location_says_so(): void
    {
        $dana = $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');

        $this->actingAs($this->admin)
            ->get("/admin/map?contact={$dana->id}")
            ->assertOk()
            ->assertSee('Dana Cruz’s address isn’t on the map yet.', false)
            ->assertSee('No location yet, so the distances aren’t known.', false);
    }

    public function test_an_unknown_or_deleted_contact_is_not_found(): void
    {
        $deleted = $this->contact('Pat Morgan', '9 Sample Rd, Fort Collins, CO 80526', 40.55, -105.1);
        $deleted->delete();

        $this->actingAs($this->admin)->get("/admin/map?contact={$deleted->id}")->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/map?contact=999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/map?contact=abc')->assertNotFound();
    }

    public function test_lists_addresses_without_a_location_to_copy(): void
    {
        $other = $this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525');
        $pickup = $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525-1234');
        $this->application($pickup);
        $site = $this->contact('South Drop-off Site', '1 Example Way, Loveland, CO 80537', site: true);
        // Left out: a located address, and a previous one
        $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);
        $moved = $this->contact('Jamie Rivera', '9 Old Rd, Fort Collins, CO 80521');
        $moved->moveTo('10 New Rd, Fort Collins, CO 80521');

        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('4 addresses need a location: drop-off sites first, then those waiting for a pickup.')
            // The commands first, so copying the addresses comes right before pasting them
            ->assertSeeInOrder(['1. Run these in your local setup', 'php artisan addresses:geocode', '2. Copy these addresses, paste them into the terminal, then press Enter', '{"addresses":[', '3. Paste what it printed'])
            // Drop-off sites first, then pickups, then everyone else, as entered, one per line
            ->assertSee(implode("\n", [
                '{"addresses":[',
                '{"id":' . $this->address($site)->id . ',"address":"1 Example Way, Loveland, CO 80537"},',
                '{"id":' . $this->address($pickup)->id . ',"address":"3 Example Blvd, Fort Collins, CO 80525-1234"},',
                '{"id":' . $this->address($other)->id . ',"address":"2 Sample Ct, Fort Collins, CO 80525"},',
                '{"id":' . $this->address($moved)->id . ',"address":"10 New Rd, Fort Collins, CO 80521"}',
                ']}',
            ]))
            ->assertDontSee('412 Sample Ave')
            ->assertDontSee('9 Old Rd');
    }

    public function test_hides_locate_addresses_when_every_address_has_a_location(): void
    {
        $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844);

        $this->actingAs($this->admin)
            ->get('/admin/map')
            // Nothing to locate, so no Locate Addresses at all
            ->assertDontSee('Locate Addresses')
            ->assertDontSee('Every address has a location.')
            ->assertDontSee('1. Run these in your local setup')
            ->assertDontSee('2. Copy these addresses')
            // Nothing to paste, so no box or Preview button
            ->assertDontSee('name="results"', false)
            ->assertDontSee('action="' . route('admin.map.locations') . '"', false);
    }

    public function test_previews_pasted_locations_then_saves_those_it_can(): void
    {
        $new = $this->address($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'));
        $located = $this->address($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844));
        $moved = $this->contact('Jamie Rivera', '9 Old Rd, Fort Collins, CO 80521');
        $previous = $this->address($moved);
        $moved->moveTo('10 New Rd, Fort Collins, CO 80521');
        $far = $this->address($this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525'));
        $street = $this->address($this->contact('Morgan Ellis', '9 Placeholder Rd, Loveland, CO 80537'));
        $missed = $this->address($this->contact('Avery Stone', '1 Nowhere Rd, Fort Collins, CO 80521'));

        $results = LocationImport::encode([
            LocationImport::LOCATIONS => [
                ['id' => $new->id, 'latitude' => 40.1, 'longitude' => -105.1],
                ['id' => $located->id, 'latitude' => 40.5854, 'longitude' => -105.0845],
                ['id' => $previous->id, 'latitude' => 40.3, 'longitude' => -105.3],
                ['id' => 999999, 'latitude' => 40.4, 'longitude' => -105.4],
                ['id' => $far->id, 'latitude' => 45.0, 'longitude' => -100.0],
                ['id' => $new->id],
                ['id' => $new->id, 'latitude' => 40.58985, 'longitude' => -105.08191],
            ],
            LocationImport::STREET_ONLY => [['id' => $street->id, 'latitude' => 40.3977, 'longitude' => -105.075]],
            LocationImport::NOT_FOUND => [['id' => $missed->id]],
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/map/locations', ['results' => $results]);

        $response->assertOk()
            ->assertSee('2 of 9 addresses can be saved. The others are left out.')
            ->assertSeeInOrder([
                'Listed again later',
                'Will replace its location',
                'Address changed since it was copied',
                'No address with this number',
                'Outside Colorado',
                'Missing its number or location',
                'Will be saved',
                '9 Placeholder Rd, Loveland, CO 80537',
                'Only the street was found',
                '1 Nowhere Rd, Fort Collins, CO 80521',
                'Not found by the geocoder',
            ])
            ->assertSee('<button type="submit" class="button dark small" name="confirm" value="1">Save 2 Locations</button>', false)
            // What was pasted stays, to save or fix
            ->assertSee('"not_found":[')
            ->assertSee('Not saved yet (2)');

        // The locations it would save are on the map, in purple, before saving
        $points = array_values(array_filter($this->points($response), fn (array $point) => $point['kind'] === 'preview'));
        $this->assertSame(['Sam Lee', 'Dana Cruz'], array_column($points, 'name'));
        $this->assertSame('Not saved yet · 3 Example Blvd, Fort Collins, CO 80525', $points[1]['note']);
        $this->assertEqualsWithDelta(40.58985, $points[1]['lat'], 1e-9);
        $this->assertEqualsWithDelta(-105.08191, $points[1]['lng'], 1e-9);

        // Nothing is saved until it's confirmed
        $this->assertNull($new->fresh()->location);

        $this->actingAs($this->admin)
            ->post('/admin/map/locations', ['results' => $results, 'confirm' => '1'])
            ->assertRedirect(route('admin.map'))
            ->assertSessionHas('status', 'Saved 2 locations.');

        $new->refresh();
        $this->assertEqualsWithDelta(40.58985, $new->location['latitude'], 1e-9);
        $this->assertEqualsWithDelta(-105.08191, $new->location['longitude'], 1e-9);
        $this->assertSame($this->admin->id, $new->located_by);
        $this->assertEqualsWithDelta(40.5854, $located->fresh()->location['latitude'], 1e-9);
        $this->assertNull($previous->fresh()->location);
        $this->assertNull($far->fresh()->location);
        // Found only at the street, or not found, are never saved
        $this->assertNull($street->fresh()->location);
        $this->assertNull($missed->fresh()->location);
    }

    public function test_pasting_anything_but_the_commands_json_says_what_to_paste(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/map/locations', ['results' => ''])
            ->assertSessionHasErrors(['results' => 'Paste what addresses:geocode printed.']);

        foreach (["42\t40.58985,-105.08191", '{"addresses":[]}', '{"locations":"none"}'] as $pasted) {
            $this->actingAs($this->admin)
                ->post('/admin/map/locations', ['results' => $pasted, 'confirm' => '1'])
                ->assertSessionHasErrors(['results' => 'Paste everything addresses:geocode printed, from the { at the start to the } at the end.']);
        }

        $this->actingAs($this->admin)
            ->post('/admin/map/locations', ['results' => '{"locations":[]}', 'confirm' => '1'])
            ->assertRedirect(route('admin.map'))
            ->assertSessionHas('status', 'No locations were saved.');
    }
}
