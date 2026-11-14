<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
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
 * pickups, opening a load to follow on a phone (/admin/map?load={id}),
 * editing and canceling it, and marking it done once it's been picked up
 * (/admin/map/loads/{id}/done). Made-up people and addresses only.
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

    public function test_a_new_load_sent_back_with_errors_keeps_its_stops_in_the_order_chosen(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);

        // Nothing chosen yet, so the script numbers pickups as they're clicked
        $this->actingAs($this->admin)
            ->get('/admin/map?build=1')
            ->assertSee('<script type="application/json" id="load-order">[]</script>', false);

        // Dana clicked first, though Sam comes first in the list
        $this->actingAs($this->admin)
            ->from('/admin/map?build=1')
            ->followingRedirects()
            ->post('/admin/map/loads', ['planned_on' => '2026-11-08', 'capacity' => '0', 'stops' => [(string) $dana->id, (string) $sam->id]])
            ->assertSee('Enter how many bags the trailer holds, such as 80.')
            ->assertSee('<script type="application/json" id="load-order">[' . $dana->id . ',' . $sam->id . ']</script>', false);
    }

    public function test_an_edited_load_sent_back_with_errors_keeps_new_stops_in_the_order_chosen(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $taylor = $this->application($this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525'), 15);
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);
        $load = $this->load('2026-11-08', [$jamie]);

        // The load's stops first, then Sam and Taylor in the order clicked
        $this->actingAs($this->admin)
            ->from(route('admin.map', ['load' => $load->id, 'edit' => 1]))
            ->followingRedirects()
            ->post("/admin/map/loads/{$load->id}", ['planned_on' => '2026-11-08', 'capacity' => '0', 'stops' => [(string) $sam->id, (string) $jamie->id, (string) $taylor->id]])
            ->assertSee('Enter how many bags the trailer holds, such as 80.')
            ->assertSee('<script type="application/json" id="load-order">[' . $jamie->id . ',' . $sam->id . ',' . $taylor->id . ']</script>', false);
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
                '<p><a href="tel:+19705550102">970-555-0102</a></p>',
                '<p class="stop-notes">Gate code 2468. Bags by the garage.</p>',
                '<span class="stop-number">2</span> <a href="' . route('admin.contacts.show', $dana->id) . '">Dana Cruz</a> <span class="stop-bags">30 bags</span>',
                '<p>3 Example Blvd, Fort Collins, CO 80525 (not on the map yet)</p>',
                // The application's number, before the contact has one
                '<p><a href="tel:+19705550100">970-555-0100</a></p>',
            ], false)
            // The same in the table on wider screens
            ->assertSee('<td class="stop-phone"><a href="tel:+19705550102">970-555-0102</a></td>', false)
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

    public function test_only_signed_in_admins_can_change_loads(): void
    {
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);
        $load = $this->load('2026-11-08', [$sam]);
        $donor = User::factory()->withPassword()->create();

        $requests = [
            "/admin/map/loads/{$load->id}",
            "/admin/map/loads/{$load->id}/stops/{$sam->id}/down",
            "/admin/map/loads/{$load->id}/cancel",
            "/admin/map/loads/{$load->id}/done",
        ];

        $this->get("/admin/map/loads/{$load->id}/done")->assertRedirect(route('login'));

        foreach ($requests as $url) {
            $this->post($url, ['confirm' => '1'])->assertRedirect(route('login'));
        }

        $this->actingAs($donor)->get("/admin/map/loads/{$load->id}/done")->assertForbidden();

        foreach ($requests as $url) {
            $this->actingAs($donor)->post($url, ['confirm' => '1'])->assertForbidden();
        }

        $this->assertTrue($load->fresh()->isPlanned());
    }

    public function test_editing_opens_the_form_with_the_load(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521', 40.5853, -105.0844), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525', 40.553, -105.101), 30);
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521', 40.56, -105.11), 40);
        $robin = $this->application($this->contact('Robin Fox', '7 Sample Ln, Fort Collins, CO 80526', 40.5961, -105.1205), 35);
        $load = $this->load('2026-11-08', [$dana, $jamie], capacity: 70);
        // In another load, so not offered
        $this->load('2026-11-09', [$robin]);

        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id]))
            // Blade writes the & in the address as &amp;
            ->assertSee('<a class="button dark small" href="' . e(route('admin.map', ['load' => $load->id, 'edit' => 1])) . '">Edit Load</a>', false);

        $response = $this->actingAs($this->admin)->get(route('admin.map', ['load' => $load->id, 'edit' => 1]));

        $response->assertOk()
            ->assertSee('<h1>Trailer Load: Sun, Nov 8</h1>', false)
            ->assertSee('<h3>Edit Load</h3>', false)
            ->assertSee('action="' . route('admin.map.loads.update', $load->id) . '"', false)
            ->assertSee('name="planned_on" value="2026-11-08"', false)
            ->assertSee('name="capacity" value="70"', false)
            ->assertSee('2 stops · 55 of 70 bags · 15 bags of room')
            ->assertSee('value="' . $dana->id . '" aria-label="Select ' . $dana->id . '" checked>', false)
            ->assertSee('value="' . $jamie->id . '" aria-label="Select ' . $jamie->id . '" checked>', false)
            ->assertSee('value="' . $sam->id . '" aria-label="Select ' . $sam->id . '">', false)
            ->assertDontSee('value="' . $robin->id . '"', false)
            // The map numbers its stops in route order
            ->assertSee('<script type="application/json" id="load-order">[' . $dana->id . ',' . $jamie->id . ']</script>', false)
            ->assertSee('This load’s stops, and the pickups not in another load, soonest first.')
            ->assertSee('<button type="submit" class="button dark small">Save Changes</button>', false)
            ->assertSee('href="' . route('admin.map', ['load' => $load->id]) . '">Cancel</a>', false)
            // Not the load's own page, nor the rest of the Map page
            ->assertDontSee('<h2 id="stops">Stops</h2>', false)
            ->assertDontSee('<h2>Trailer Loads</h2>', false)
            ->assertDontSee('<label for="show">Show</label>', false);
    }

    public function test_saving_changes_keeps_the_order_and_adds_new_stops_at_the_end(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $taylor = $this->application($this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525'), 15);
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);
        $load = $this->load('2026-11-08', [$jamie, $dana, $taylor]);

        // Sent in the list's order: Dana taken out, Sam added
        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}", [
                'planned_on' => '2026-11-09',
                'capacity' => '90',
                'stops' => [(string) $taylor->id, (string) $sam->id, (string) $jamie->id],
            ])
            ->assertRedirect(route('admin.map', ['load' => $load->id]))
            ->assertSessionHas('status', 'Saved the Mon, Nov 9 load: 3 stops, 80 bags.');

        $load->refresh();
        $this->assertSame('2026-11-09', $load->planned_on->toDateString());
        $this->assertSame(90, $load->capacity);
        $this->assertSame([$jamie->id, $taylor->id, $sam->id], $load->applications->pluck('id')->all());
        $this->assertSame([1, 2, 3], $load->applications->pluck('pivot.position')->map(fn ($position) => (int) $position)->all());

        // Dana needs scheduling again, and her application is untouched
        $this->actingAs($this->admin)->get('/admin/map')->assertSee('<summary>Dana Cruz', false);
        $this->assertSame(LeafApplication::STATUS_PENDING, $dana->fresh()->status);
    }

    public function test_a_stop_picked_up_on_its_own_stays_in_the_load(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $load = $this->load('2026-11-08', [$dana, $jamie]);
        $dana->forceFill(['status' => LeafApplication::STATUS_COMPLETE])->save();

        // Dana isn't offered, since her pickup was logged
        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id, 'edit' => 1]))
            ->assertDontSee('value="' . $dana->id . '"', false);

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}", ['planned_on' => '2026-11-08', 'capacity' => '80', 'stops' => [(string) $jamie->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$dana->id, $jamie->id], $load->fresh()->applications->pluck('id')->all());
    }

    public function test_moves_stops_up_and_down_the_route(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $taylor = $this->application($this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525'), 15);
        $load = $this->load('2026-11-08', [$jamie, $dana, $taylor]);
        $order = fn () => $load->fresh()->applications->pluck('id')->all();

        // The first stop can only go down, the last only up
        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id]))
            ->assertSee('In your route’s order. Move a stop up or down to change it.')
            ->assertSee('action="' . route('admin.map.loads.move', [$load->id, $dana->id, 'up']) . '"', false)
            ->assertSee('aria-label="Move Dana Cruz down">Down</button>', false)
            ->assertDontSee('aria-label="Move Jamie Rivera up"', false)
            ->assertDontSee('aria-label="Move Taylor Brooks down"', false);

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/stops/{$dana->id}/up")
            ->assertRedirect(route('admin.map', ['load' => $load->id]) . '#stops');
        $this->assertSame([$dana->id, $jamie->id, $taylor->id], $order());

        $this->actingAs($this->admin)->post("/admin/map/loads/{$load->id}/stops/{$jamie->id}/down");
        $this->assertSame([$dana->id, $taylor->id, $jamie->id], $order());

        // Past either end, nothing changes
        $this->actingAs($this->admin)->post("/admin/map/loads/{$load->id}/stops/{$dana->id}/up");
        $this->actingAs($this->admin)->post("/admin/map/loads/{$load->id}/stops/{$jamie->id}/down");
        $this->assertSame([$dana->id, $taylor->id, $jamie->id], $order());
        $this->assertSame([1, 2, 3], $load->fresh()->applications->pluck('pivot.position')->map(fn ($position) => (int) $position)->all());

        // A pickup not in the load
        $other = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 40);
        $this->actingAs($this->admin)->post("/admin/map/loads/{$load->id}/stops/{$other->id}/up")->assertNotFound();
    }

    public function test_cancels_a_load_once_confirmed(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $load = $this->load('2026-11-08', [$jamie, $dana]);

        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id]))
            ->assertSeeInOrder(['<h2 id="cancel">Cancel This Load</h2>', 'action="' . route('admin.map.loads.cancel', $load->id) . '"', 'Cancel the Sun, Nov 8 load', 'Its stops go back to Needs scheduling. The load is kept, marked Canceled.', 'Cancel Load</button>'], false);

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/cancel")
            ->assertRedirect(route('admin.map', ['load' => $load->id]) . '#cancel')
            ->assertSessionHasErrors(['confirm' => 'Check the box to confirm, then press Cancel Load.']);
        $this->assertTrue($load->fresh()->isPlanned());

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/cancel", ['confirm' => '1'])
            ->assertRedirect(route('admin.map'))
            ->assertSessionHas('status', 'Canceled the Sun, Nov 8 load. Its 2 stops need scheduling again.');

        $load->refresh();
        $this->assertSame(TrailerLoad::STATUS_CANCELED, $load->status);
        $this->assertSame($this->admin->id, $load->finished_by);
        $this->assertNotNull($load->finished_at);
        // Kept, with its stops, and listed with the past loads
        $this->assertSame(2, $load->applications->count());
        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('2 pickups need scheduling, 55 bags in all')
            ->assertSee('<summary>Sun, Nov 8 · Canceled · 55 of 80 bags</summary>', false);
    }

    public function test_a_done_or_canceled_load_cannot_be_changed(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $load = $this->load('2026-11-08', [$jamie, $dana], TrailerLoad::STATUS_CANCELED);

        // Its page has nothing to change it, even when asked to edit
        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id, 'edit' => 1]))
            ->assertSee('Canceled · 2 stops')
            ->assertDontSee('Edit Load')
            ->assertDontSee('Mark Done')
            ->assertDontSee('Move Dana Cruz')
            ->assertDontSee('Cancel This Load');

        foreach ([
            ["/admin/map/loads/{$load->id}", ['planned_on' => '2026-11-09', 'capacity' => '80', 'stops' => [(string) $jamie->id]]],
            ["/admin/map/loads/{$load->id}/stops/{$dana->id}/up", []],
            ["/admin/map/loads/{$load->id}/cancel", ['confirm' => '1']],
            ["/admin/map/loads/{$load->id}/done", ['finished_at' => '2026-11-06T09:00', 'stops' => [(string) $jamie->id], 'bags' => [$jamie->id => '25']]],
        ] as [$url, $input]) {
            $this->actingAs($this->admin)
                ->post($url, $input)
                ->assertRedirect(route('admin.map', ['load' => $load->id]))
                ->assertSessionHas('status', 'This load is canceled, so it can’t be changed.');
        }

        $this->actingAs($this->admin)
            ->get("/admin/map/loads/{$load->id}/done")
            ->assertRedirect(route('admin.map', ['load' => $load->id]))
            ->assertSessionHas('status', 'This load is canceled, so it can’t be changed.');

        $load->refresh();
        $this->assertSame('2026-11-08', $load->planned_on->toDateString());
        $this->assertSame([$jamie->id, $dana->id], $load->applications->pluck('id')->all());
        $this->assertSame(0, ContactActivity::count());
        $this->assertSame(LeafApplication::STATUS_PENDING, $jamie->fresh()->status);
    }

    public function test_mark_done_lists_the_stops_to_log_checked(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        // Not linked to a contact yet, and one picked up on its own
        $morgan = $this->application($this->contact('Morgan Ellis', '88 Placeholder Ln, Fort Collins, CO 80526'), 40, ['contact_id' => null, 'name' => 'Morgan Ellis']);
        $sam = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 20);
        $load = $this->load('2026-11-08', [$jamie, $dana, $morgan, $sam]);
        $sam->forceFill(['status' => LeafApplication::STATUS_COMPLETE])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id]))
            ->assertSee('<a class="button dark small" href="' . route('admin.map.loads.done', $load->id) . '">Mark Done</a>', false);

        $this->actingAs($this->admin)
            ->get(route('admin.map.loads.done', $load->id))
            ->assertOk()
            ->assertSee('<h1>Mark Load Done</h1>', false)
            ->assertSee('<a href="' . route('admin.map') . '" aria-current="page">', false)
            ->assertSee('Sun, Nov 8 load · 3 stops to log')
            ->assertSee('action="' . route('admin.map.loads.finish', $load->id) . '"', false)
            ->assertSee('name="finished_at" value="2026-11-06T12:00"', false)
            ->assertSeeInOrder([
                '<input type="checkbox" id="stop-' . $jamie->id . '" name="stops[]" value="' . $jamie->id . '" checked>',
                '1. Jamie Rivera',
                '10 New Rd, Fort Collins, CO 80521',
                'name="bags[' . $jamie->id . ']" value="25"',
                'estimated 25',
                '<input type="checkbox" id="stop-' . $dana->id . '" name="stops[]" value="' . $dana->id . '" checked>',
                '2. Dana Cruz',
                'name="bags[' . $dana->id . ']" value="30"',
                '3. Morgan Ellis',
                'Not linked to a contact, so its pickup can’t be logged here.',
            ], false)
            ->assertDontSee('name="stops[]" value="' . $morgan->id . '"', false)
            ->assertDontSee('Sam Lee')
            ->assertSee('1 other stop was already picked up or deleted, so it isn’t listed.')
            ->assertSee('Mark Done</button>', false)
            ->assertSee('href="' . route('admin.map', ['load' => $load->id]) . '">Cancel</a>', false);
    }

    public function test_marking_a_load_done_logs_a_pickup_for_each_stop_checked(): void
    {
        $jamieContact = $this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521');
        $danaContact = $this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525');
        $jamie = $this->application($jamieContact, 25);
        $dana = $this->application($danaContact, 30);
        $taylor = $this->application($this->contact('Taylor Brooks', '2 Sample Ct, Fort Collins, CO 80525'), 15);
        $load = $this->load('2026-11-08', [$jamie, $dana, $taylor]);

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/done", [
                'finished_at' => '2026-11-06T09:30',
                'stops' => [(string) $jamie->id, (string) $dana->id],
                // Typed by people; an unchecked stop's bags are ignored
                'bags' => [$jamie->id => '28', $dana->id => ' 1,0 ', $taylor->id => 'none'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.map', ['load' => $load->id]))
            ->assertSessionHas('status', 'Logged 2 pickups, 38 bags, and marked the Sun, Nov 8 load done. 1 stop needs scheduling again.');

        $load->refresh();
        $this->assertSame(TrailerLoad::STATUS_DONE, $load->status);
        $this->assertSame($this->admin->id, $load->finished_by);
        $this->assertNotNull($load->finished_at);

        // A pickup on each contact's timeline, which completes the application
        $pickup = $jamieContact->activities()->sole();
        $this->assertSame(ContactActivity::TYPE_PICKUP, $pickup->type);
        $this->assertSame(28, $pickup->bag_count);
        $this->assertSame($jamie->id, $pickup->leaf_application_id);
        $this->assertSame($this->admin->id, $pickup->logged_by);
        $this->assertSame('2026-11-06 16:30', $pickup->happened_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(10, $danaContact->activities()->sole()->bag_count);
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $jamie->fresh()->status);
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $dana->fresh()->status);
        $this->assertSame(LeafApplication::STATUS_PENDING, $taylor->fresh()->status);

        // Kept on each stop picked up
        $this->assertSame(
            [$jamie->id => $pickup->id, $dana->id => $danaContact->activities()->sole()->id, $taylor->id => null],
            $load->applications->mapWithKeys(fn (LeafApplication $application) => [$application->id => $application->pivot->contact_activity_id === null ? null : (int) $application->pivot->contact_activity_id])->all(),
        );

        // The load says what was picked up, and can't be changed
        $this->actingAs($this->admin)
            ->get(route('admin.map', ['load' => $load->id]))
            ->assertSee('Done · 3 stops')
            ->assertSee('<th scope="col">Pickup</th>', false)
            ->assertSeeInOrder(['<p class="stop-pickup">Picked up 28 bags</p>', '<p class="stop-pickup">Picked up 10 bags</p>', '<p class="stop-pickup">Not picked up</p>'], false)
            ->assertDontSee('Mark Done')
            ->assertDontSee('Edit Load');

        // Taylor needs scheduling again
        $this->actingAs($this->admin)
            ->get('/admin/map')
            ->assertSee('1 pickup needs scheduling, 15 bags in all');
    }

    public function test_mark_done_says_what_to_fix_and_keeps_the_form(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $dana = $this->application($this->contact('Dana Cruz', '3 Example Blvd, Fort Collins, CO 80525'), 30);
        $load = $this->load('2026-11-08', [$jamie, $dana]);
        $input = [
            'finished_at' => '2026-11-07T09:30',
            'stops' => [(string) $jamie->id],
            'bags' => [$jamie->id => '', $dana->id => '31'],
        ];

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/done", $input)
            ->assertRedirect(route('admin.map.loads.done', $load->id))
            ->assertSessionHasErrors([
                'finished_at' => 'Enter a time that has already happened.',
                "bags.{$jamie->id}" => 'Enter how many bags were picked up.',
            ]);

        // The form as it was: Dana unchecked, with her bags as typed
        $this->actingAs($this->admin)
            ->from(route('admin.map.loads.done', $load->id))
            ->followingRedirects()
            ->post("/admin/map/loads/{$load->id}/done", $input)
            ->assertSee('name="finished_at" value="2026-11-07T09:30"', false)
            ->assertSee('<input type="checkbox" id="stop-' . $jamie->id . '" name="stops[]" value="' . $jamie->id . '" checked>', false)
            ->assertSee('<input type="checkbox" id="stop-' . $dana->id . '" name="stops[]" value="' . $dana->id . '">', false)
            ->assertSee('name="bags[' . $dana->id . ']" value="31"', false)
            ->assertSee('Enter how many bags were picked up.');

        $this->assertTrue($load->fresh()->isPlanned());
        $this->assertSame(0, ContactActivity::count());
    }

    public function test_only_the_loads_stops_still_to_log_can_be_logged(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $morgan = $this->application($this->contact('Morgan Ellis', '88 Placeholder Ln, Fort Collins, CO 80526'), 40, ['contact_id' => null]);
        $other = $this->application($this->contact('Sam Lee', '412 Sample Ave, Fort Collins, CO 80521'), 20);
        $load = $this->load('2026-11-08', [$jamie, $morgan]);

        foreach ([$morgan, $other] as $application) {
            $this->actingAs($this->admin)
                ->post("/admin/map/loads/{$load->id}/done", [
                    'finished_at' => '2026-11-06T09:30',
                    'stops' => [(string) $jamie->id, (string) $application->id],
                    'bags' => [$jamie->id => '25', $application->id => '20'],
                ])
                ->assertSessionHasErrors(['stops' => 'A stop changed since this page opened. Check the list, then try again.']);
        }

        $this->assertTrue($load->fresh()->isPlanned());
        $this->assertSame(0, ContactActivity::count());
    }

    public function test_a_load_can_be_marked_done_without_pickups(): void
    {
        $jamie = $this->application($this->contact('Jamie Rivera', '10 New Rd, Fort Collins, CO 80521'), 25);
        $load = $this->load('2026-11-08', [$jamie]);

        $this->actingAs($this->admin)
            ->post("/admin/map/loads/{$load->id}/done", ['finished_at' => '2026-11-06T09:30', 'bags' => [$jamie->id => '25']])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Marked the Sun, Nov 8 load done without logging any pickups. 1 stop needs scheduling again.');

        $this->assertSame(TrailerLoad::STATUS_DONE, $load->fresh()->status);
        $this->assertSame(LeafApplication::STATUS_PENDING, $jamie->fresh()->status);
    }
}
