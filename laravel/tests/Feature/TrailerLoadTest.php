<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Trailer loads on the admin Map page: starting one and choosing pickups
 * (/admin/map?build=1), saving it, Needs scheduling leaving out pickups in
 * a planned load, the loads listed above the map, Everything ringing their
 * pickups, and opening a load to follow on a phone (/admin/map?load={id}).
 * Made-up people and addresses only.
 */
class TrailerLoadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        // A Friday, so Nov 8 is a Sunday
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    /**
     * A contact with an address, located when given a latitude and
     * longitude.
     */
    private function contact(string $name, string $address, ?float $latitude = null, ?float $longitude = null): Contact
    {
        $contact = Contact::create(['name' => $name]);
        $detail = $contact->moveTo($address);

        if ($latitude !== null) {
            $detail->locate($latitude, $longitude, $this->admin);
        }

        return $contact;
    }

    /**
     * A pending pickup application of the contact's, ready now.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(Contact $contact, int $bags, array $attributes = []): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $contact->name,
            'email' => 'applicant@example.com',
            'phone' => '(970) 555-0100',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => $bags,
            'ready_now' => true,
            'terms_accepted_at' => now()->subDays(3),
            'contact_id' => $contact->id,
            'created_at' => now()->subDays(3),
            ...$attributes,
        ])->save();

        return $application;
    }

    /**
     * A load with these applications as its stops, in order.
     *
     * @param  list<LeafApplication>  $applications
     */
    private function load(string $plannedOn, array $applications, string $status = TrailerLoad::STATUS_PLANNED, int $capacity = 80): TrailerLoad
    {
        $load = new TrailerLoad(['planned_on' => $plannedOn, 'capacity' => $capacity]);
        $load->forceFill(['status' => $status, 'created_by' => $this->admin->id])->save();

        foreach ($applications as $index => $application) {
            $load->applications()->attach($application->id, ['position' => $index + 1]);
        }

        return $load;
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

    public function test_only_signed_in_admins_can_save_loads(): void
    {
        $this->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '80', 'stops' => ['1']])->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '80', 'stops' => ['1']])->assertForbidden();

        $this->assertSame(0, TrailerLoad::count());
    }

    public function test_starting_a_load_shows_the_form_and_a_box_for_each_pickup(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844), 40);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);

        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('<a class="button dark small" href="' . route('admin.map', ['build' => 1]) . '">Start a Trailer Load</a>', false)
            ->assertDontSee('New Trailer Load');

        $response = $this->actingAs($this->admin)->get('/admin/map?build=1');

        $response->assertOk()
            ->assertSee('<h3>New Trailer Load</h3>', false)
            ->assertSee('action="' . route('admin.map.loads.store') . '"', false)
            ->assertSee('name="planned_on" value="2026-11-06"', false)
            ->assertSee('name="capacity" value="80"', false)
            ->assertSee('0 stops · 0 of 80 bags · 80 bags of room')
            // Boxes for the form, including a pickup not on the map yet
            ->assertSee('<input type="checkbox" form="trailer-load" name="stops[]" value="' . $sam->id . '"', false)
            ->assertSee('<input type="checkbox" form="trailer-load" name="stops[]" value="' . $dana->id . '"', false)
            // What each pickup weighs, for the progress bar
            ->assertSee('<script type="application/json" id="load-bags">{"' . $sam->id . '":40,"' . $dana->id . '":30}</script>', false)
            ->assertDontSee('Start a Trailer Load</a>', false);

        // The script adds a pickup by its dot
        $this->assertSame($sam->id, $this->points($response)['Sam Lee']['id']);
    }

    public function test_saves_a_load_with_its_stops_in_the_order_chosen(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844), 40);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525', 40.553, -105.101), 60);

        // Each box is on the page twice, so a pickup can come twice
        $response = $this->actingAs($this->admin)->post('/admin/map/loads', [
            'planned_on' => '2026-11-08',
            'capacity' => ' 80 ',
            'stops' => [(string) $dana->id, (string) $sam->id, (string) $dana->id],
        ]);

        $load = TrailerLoad::sole();
        $response->assertRedirect(route('admin.map', ['load' => $load->id]))
            ->assertSessionHas('status', 'Saved the Sun, Nov 8 load: 2 stops, 100 bags.');

        $this->assertSame('2026-11-08', $load->planned_on->toDateString());
        $this->assertSame(80, $load->capacity);
        $this->assertSame(TrailerLoad::STATUS_PLANNED, $load->status);
        $this->assertSame($this->admin->id, $load->created_by);
        $this->assertSame([$dana->id, $sam->id], $load->applications->pluck('id')->all());
        $this->assertSame([1, 2], $load->applications->pluck('pivot.position')->map(fn ($position) => (int) $position)->all());
    }

    public function test_a_load_can_be_over_its_capacity(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);

        $this->actingAs($this->admin)
            ->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '30', 'stops' => [(string) $sam->id]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => TrailerLoad::sole()->id]))
            ->assertSee('<div class="load-bar over">', false)
            ->assertSee('1 stop · 40 of 30 bags · 10 over');
    }

    public function test_says_what_to_fix_and_keeps_the_choices(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);

        $this->actingAs($this->admin)
            ->from('/admin/map?build=1')
            ->post('/admin/map/loads', ['planned_on' => 'tomorrow', 'capacity' => '0'])
            ->assertRedirect('/admin/map?build=1')
            ->assertSessionHasErrors([
                'planned_on' => 'Enter the day the load is planned for.',
                'capacity' => 'Enter how many bags the trailer holds, such as 80.',
                'stops' => 'Choose at least one pickup: click it on the map, or check it in the list.',
            ]);

        // On a request of its own, since asserting session errors leaves none for the next one
        $this->actingAs($this->admin)
            ->from('/admin/map?build=1')
            ->followingRedirects()
            ->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '1,500', 'stops' => [(string) $sam->id]])
            ->assertSee('Enter a capacity of 1,000 bags or fewer.')
            ->assertSee('name="planned_on" value="2026-11-08"', false)
            // The capacity as a number the box can show, without its comma
            ->assertSee('name="capacity" value="1500"', false)
            ->assertSee('value="' . $sam->id . '" aria-label="Select ' . $sam->id . '" checked>', false)
            ->assertSee('1 stop · 40 of 1,500 bags · 1,460 bags of room');

        $this->assertSame(0, TrailerLoad::count());
    }

    public function test_only_pending_pickups_can_be_stops(): void
    {
        $contact = $this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521');
        $dropOff = $this->application($contact, 20, ['type' => LeafApplication::TYPE_DROP_OFF]);
        $done = $this->application($contact, 20, ['status' => LeafApplication::STATUS_COMPLETE]);

        foreach ([$dropOff, $done] as $application) {
            $this->actingAs($this->admin)
                ->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '80', 'stops' => [(string) $application->id]])
                ->assertSessionHasErrors(['stops' => 'A pickup you chose has been picked up or removed since the page opened. Check the pickups again.']);
        }

        $this->assertSame(0, TrailerLoad::count());
    }

    public function test_a_pickup_in_a_planned_load_leaves_needs_scheduling(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844), 40);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525', 40.553, -105.101), 30);
        $load = $this->load('2026-11-08', [$sam]);

        $response = $this->actingAs($this->admin)->get('/admin/map');
        $response->assertSee('1 pickup needs scheduling, 30 bags in all, all on the map. 1 more is in a planned load.')
            ->assertDontSee('<summary>Sam Lee', false);
        $this->assertSame(['Dana Cruz'], array_keys($this->points($response)));

        // Nor can it go in another planned load
        $this->actingAs($this->admin)
            ->post('/admin/map/loads', ['planned_on' => '2026-11-09', 'capacity' => '80', 'stops' => [(string) $sam->id, (string) $dana->id]])
            ->assertSessionHasErrors(['stops' => 'A pickup you chose is already in another planned load. Check the pickups again.']);

        // Once its load is canceled, it needs scheduling again
        $load->forceFill(['status' => TrailerLoad::STATUS_CANCELED])->save();

        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('2 pickups need scheduling, 70 bags in all, all on the map.')
            ->assertSee('<summary>Sam Lee', false);
    }

    public function test_says_when_every_pickup_is_in_a_load(): void
    {
        $this->load('2026-11-08', [$this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40)]);

        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('Every pickup is in a planned trailer load.')
            ->assertDontSee('<h2>Pickups</h2>', false);
    }

    public function test_the_map_lists_the_loads_above_the_map_on_every_view(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.5853, -105.0844), 40);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525', 40.553, -105.101), 60);
        $robin = $this->application($this->contact('Robin Fox', '7 Sample Ln, Fort Collins, CO 80526', 40.5961, -105.1205), 35);
        // Not scheduled
        $this->application($this->contact('Chris Park', '9 Placeholder Rd, Fort Collins, CO 80525', 40.56, -105.11), 25);
        $done = $this->application($this->contact('Pat Morgan', '5 Sample Dr, Fort Collins, CO 80521', 40.57, -105.07), 20, ['status' => LeafApplication::STATUS_COMPLETE]);

        $this->load('2026-11-09', [$robin]);
        $this->load('2026-11-08', [$sam, $dana], capacity: 90);
        $this->load('2026-11-01', [$done], TrailerLoad::STATUS_DONE, 75);
        $this->load('2026-10-31', [], TrailerLoad::STATUS_CANCELED);

        foreach (['/admin/map', '/admin/map?show=everything'] as $url) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                // Planned soonest first, then the past ones folded, newest first, all before the map
                ->assertSeeInOrder([
                    '<h2>Trailer Loads</h2>',
                    '<summary>Sun, Nov 8 · 100 of 90 bags</summary>',
                    '<summary>Mon, Nov 9 · 35 of 80 bags</summary>',
                    '<summary>Past loads (2)</summary>',
                    '<summary>Sun, Nov 1 · Done · 20 of 75 bags</summary>',
                    '<summary>Sat, Oct 31 · Canceled · 0 of 80 bags</summary>',
                    'id="map-data"',
                ], false)
                ->assertDontSee('>Scheduled</option>', false);
        }

        // Everything rings the pickups in a planned load, with their load in the popup
        $points = $this->points($this->actingAs($this->admin)->get('/admin/map?show=everything'));
        $this->assertTrue($points['Dana Cruz']['ringed']);
        $this->assertSame('In the Sun, Nov 8 load · stop 2', $points['Dana Cruz']['note']);
        $this->assertTrue($points['Robin Fox']['ringed']);
        $this->assertArrayNotHasKey('ringed', $points['Chris Park']);
        $this->assertArrayNotHasKey('ringed', $points['Pat Morgan']);
    }

    public function test_says_when_no_loads_are_planned(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSeeInOrder(['<h2>Trailer Loads</h2>', 'No trailer loads are planned.'], false)
            ->assertDontSee('Past loads');
    }

    public function test_opens_a_load_with_its_stops_in_route_order(): void
    {
        $jamie = $this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521', 40.5853, -105.0844);
        $jamie->usePrimary(ContactDetail::TYPE_PHONE, '(970) 555-0102');
        $jamie->update(['notes' => 'Gate code 2468. Bags by the garage.']);
        $dana = $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');
        $load = $this->load('2026-11-08', [$this->application($jamie, 25), $this->application($dana, 30)]);
        $this->contact('North Drop-off Site', '1 Example Way, Fort Collins, CO 80524', 40.565, -105.09)->update(['is_drop_off_site' => true]);

        $response = $this->actingAs($this->admin)->get(route('admin.map', ['load' => $load->id]));

        $response->assertOk()
            ->assertSee('<h1>Trailer Load: Sun, Nov 8</h1>', false)
            ->assertSee('Planned · 2 stops · 1 not on the map yet')
            ->assertSee('2 stops · 55 of 80 bags · 25 bags of room')
            // On phones, a card for each stop in route order, with everything shown
            ->assertSeeInOrder([
                '<span class="stop-number">1</span> <a href="' . route('admin.contacts.show', $jamie->id) . '">Jamie Rivera</a> <span class="stop-bags">25 bags</span>',
                '<p>10 New Rd, Fort Collins, CO 80521</p>',
                '<p><a href="tel:+19705550102">(970) 555-0102</a></p>',
                '<p class="stop-notes">Gate code 2468. Bags by the garage.</p>',
                '<span class="stop-number">2</span> <a href="' . route('admin.contacts.show', $dana->id) . '">Dana Cruz</a> <span class="stop-bags">30 bags</span>',
                '<p>3 Example Blvd, Fort Collins, CO 80525 (not on the map yet)</p>',
                // The application's number, before the contact has one
                '<p><a href="tel:+19705550100">(970) 555-0100</a></p>',
            ], false)
            // The same in the table on wider screens
            ->assertSee('<a href="tel:+19705550102">(970) 555-0102</a></td>', false)
            ->assertDontSee('>Print</button>', false)
            ->assertSee('href="' . route('admin.map') . '">← Back to Map</a>', false)
            // Nothing else from the Map page
            ->assertDontSee('<label for="show">Show</label>', false)
            ->assertDontSee('<h2>Trailer Loads</h2>', false)
            ->assertDontSee('Locate Addresses');

        $points = $this->points($response);
        $this->assertSame(['Jamie Rivera', 'North Drop-off Site'], array_keys($points));
        $this->assertSame(1, $points['Jamie Rivera']['number']);
        $this->assertSame('Stop 1 · 25 bags', $points['Jamie Rivera']['note']);
    }

    public function test_an_unknown_load_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/map?load=999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/map?load=abc')->assertNotFound();
    }
}
