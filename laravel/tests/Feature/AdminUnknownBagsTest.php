<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Logging bags found at drop-off sites with no known donor, from the Leaf
 * Ledger (/admin/leaves/ledger/unknown/new).
 */
class AdminUnknownBagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $north;

    private Contact $south;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
        $this->north = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $this->south = Contact::create(['name' => 'South Drop-off Site', 'is_drop_off_site' => true]);
    }

    /**
     * Log unknown bags as the admin.
     *
     * @param  array<string, mixed>  $input
     */
    private function log(array $input)
    {
        return $this->actingAs($this->admin)->post('/admin/leaves/ledger/unknown', [
            'happened_at' => '2026-11-13T08:30',
            'drop_off_site_id' => $this->north->id,
            'bag_count' => '6',
            ...$input,
        ]);
    }

    public function test_only_signed_in_admins_can_log(): void
    {
        $this->get('/admin/leaves/ledger/unknown/new')->assertRedirect(route('login'));
        $this->post('/admin/leaves/ledger/unknown', ['bag_count' => '6'])->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->get('/admin/leaves/ledger/unknown/new')->assertForbidden();
        $this->actingAs($donor)->post('/admin/leaves/ledger/unknown', ['bag_count' => '6'])->assertForbidden();

        $this->assertSame(0, ContactActivity::count());
    }

    public function test_the_ledger_links_to_the_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger')
            ->assertSee("and as unknown bags left at drop-off sites.", false)
            ->assertSee('<a class="button dark small" href="' . route('admin.leaves.ledger.unknown.create') . '">Log Unknown Bags</a>', false);
    }

    public function test_the_form_offers_the_drop_off_sites_and_earlier_brands(): void
    {
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        foreach (['Home Depot', 'Ace Hardware', 'ace hardware', 'Ace Hardware'] as $brand) {
            $jamie->activities()->create(['type' => ContactActivity::TYPE_PICKUP, 'happened_at' => now()->subDay(), 'bag_count' => 5, 'bag_brand' => $brand]);
        }
        $deleted = Contact::create(['name' => 'Old Drop-off Site', 'is_drop_off_site' => true]);
        $deleted->delete();

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger/unknown/new')
            ->assertOk()
            ->assertSee('<h1>Log Unknown Bags</h1>', false)
            ->assertSee('name="happened_at" value="2026-11-13T10:00"', false)
            ->assertSeeInOrder(['<option value="">Choose a site</option>', 'North Drop-off Site', 'South Drop-off Site'], false)
            ->assertDontSee('Old Drop-off Site')
            ->assertDontSee('Jamie Rivera')
            // Most used first, one spelling for each
            ->assertSeeInOrder(['<datalist id="bag-brands">', '<option value="Ace Hardware"></option>', '<option value="Home Depot"></option>', '</datalist>'], false)
            ->assertDontSee('<option value="ace hardware"></option>', false)
            ->assertSee('href="' . route('admin.leaves.ledger') . '">Cancel</a>', false);
    }

    public function test_logs_a_drop_off_with_no_contact(): void
    {
        Contact::create(['name' => 'Jamie Rivera'])->activities()->create(['type' => ContactActivity::TYPE_PICKUP, 'happened_at' => now()->subDay(), 'bag_count' => 5, 'bag_brand' => 'Ace Hardware']);

        $this->log(['bag_count' => '1,2', 'bag_brand' => ' ace  hardware ', 'body' => 'By the gate'])
            ->assertRedirect(route('admin.leaves.ledger', ['season' => 2026]))
            ->assertSessionHas('status', 'Logged 12 unknown bags at North Drop-off Site.');

        $entry = ContactActivity::whereNull('contact_id')->sole();
        $this->assertTrue($entry->isUnknown());
        $this->assertSame(ContactActivity::TYPE_DROP_OFF, $entry->type);
        $this->assertSame(12, $entry->bag_count);
        $this->assertSame('Ace Hardware', $entry->bag_brand);
        $this->assertSame('By the gate', $entry->body);
        $this->assertSame($this->north->id, $entry->drop_off_site_id);
        $this->assertSame($this->admin->id, $entry->logged_by);
        $this->assertNull($entry->leaf_application_id);
        $this->assertTrue($entry->happened_at->eq(Carbon::parse('2026-11-13 08:30', 'America/Denver')));

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger?season=2026')
            ->assertSee('<p class="status" role="status">Logged 12 unknown bags at North Drop-off Site.</p>', false)
            ->assertSeeInOrder(['Nov 13, 8:30 AM', 'Unknown', '12', 'Drop-off', 'By the gate']);
    }

    public function test_save_and_add_another_keeps_the_time_and_site(): void
    {
        $this->log(['drop_off_site_id' => $this->south->id, 'bag_count' => '1', 'bag_brand' => 'Home Depot', 'body' => 'One bag', 'add_another' => '1'])
            ->assertRedirect(route('admin.leaves.ledger.unknown.create'))
            ->assertSessionHas('status', 'Logged 1 unknown bag at South Drop-off Site.');

        // Ready for the next brand: the same time and site, with no bags, brand, or notes
        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger/unknown/new')
            ->assertSee('<p class="status" role="status">Logged 1 unknown bag at South Drop-off Site.</p>', false)
            ->assertSee('name="happened_at" value="2026-11-13T08:30"', false)
            ->assertSee('<option value="' . $this->south->id . '" selected>South Drop-off Site</option>', false)
            ->assertSee('name="bag_count" min="1" max="10000" value=""', false)
            ->assertDontSee('One bag');
    }

    public function test_errors_are_shown_and_input_is_kept(): void
    {
        $this->log(['drop_off_site_id' => '', 'bag_count' => '', 'happened_at' => 'yesterday', 'bag_brand' => str_repeat('a', 101)])
            ->assertSessionHasErrors([
                'happened_at' => 'Enter the date and time the bags were found.',
                'drop_off_site_id' => 'Choose the drop-off site the bags were left at.',
                'bag_count' => 'Enter how many bags.',
                'bag_brand' => 'Keep the brand to 100 characters or fewer.',
            ]);

        // Contacts that aren't drop-off sites, and later today, are refused
        $this->log(['drop_off_site_id' => Contact::create(['name' => 'Jamie Rivera'])->id])
            ->assertSessionHasErrors(['drop_off_site_id' => 'Choose one of the drop-off sites.']);
        $this->log(['happened_at' => '2026-11-13T11:00'])
            ->assertSessionHasErrors(['happened_at' => 'Enter a time that has already happened.']);
        $this->log(['bag_count' => '0'])
            ->assertSessionHasErrors(['bag_count' => 'Enter how many bags as a whole number, such as 25.']);

        $this->assertSame(0, ContactActivity::count());

        // On a request of its own, since asserting session errors leaves none for the next one
        $this->actingAs($this->admin)
            ->from('/admin/leaves/ledger/unknown/new')
            ->followingRedirects()
            ->post('/admin/leaves/ledger/unknown', ['happened_at' => '2026-11-13T08:30', 'drop_off_site_id' => $this->south->id, 'bag_brand' => 'Home Depot', 'body' => 'By the gate'])
            ->assertSee('Enter how many bags.')
            ->assertSee('<option value="' . $this->south->id . '" selected>South Drop-off Site</option>', false)
            ->assertSee('value="Home Depot"', false)
            ->assertSee('>By the gate</textarea>', false);
    }

    public function test_without_drop_off_sites_the_form_is_left_out(): void
    {
        $this->north->delete();
        $this->south->delete();

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger/unknown/new')
            ->assertOk()
            ->assertSee('No drop-off sites yet. Drop-off sites are contacts with the Drop-off site box checked on the edit page.')
            ->assertDontSee('action="' . route('admin.leaves.ledger.unknown.store') . '"', false);
    }
}
