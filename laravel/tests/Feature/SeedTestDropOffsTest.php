<?php

namespace Tests\Feature;

use App\Console\Commands\SeedTestDropOffs;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * leaves:seed-drop-offs, which creates made-up drop-offs needing attention
 * for local testing: drop-offs with no drop-off site, and drop-offs with no
 * contact yet at the drop-off sites.
 */
class SeedTestDropOffsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    public function test_creates_drop_offs_with_no_site_and_with_no_contact(): void
    {
        $this->artisan('leaves:seed-drop-offs', ['count' => '4', '--unknown' => '2'])
            ->expectsOutputToContain('Added test drop-off sites: North Test Drop-off Site and South Test Drop-off Site.')
            ->expectsOutputToContain('Created 4 drop-offs with no drop-off site, each for a new contact, and 2 drop-offs with no contact at the drop-off sites.')
            ->assertSuccessful();

        $sites = Contact::query()->dropOffSites()->get();
        $this->assertSame(SeedTestDropOffs::SITES, $sites->pluck('name')->all());

        foreach ($sites as $site) {
            $this->assertSame($this->admin->id, $site->address()->located_by, $site->name);
        }

        // Each for a new contact, completing their drop-off application
        $noSite = ContactActivity::whereNotNull('contact_id')->with(['contact', 'application'])->get();
        $this->assertCount(4, $noSite);

        foreach ($noSite as $entry) {
            $this->assertSame(ContactActivity::TYPE_DROP_OFF, $entry->type);
            $this->assertNull($entry->drop_off_site_id);
            $this->assertGreaterThanOrEqual(1, $entry->bag_count);
            $this->assertTrue($entry->bag_brand === null || in_array($entry->bag_brand, SeedTestDropOffs::BRANDS, true));
            $this->assertSame($this->admin->id, $entry->logged_by);
            $this->assertTrue($entry->happened_at->between(now()->subWeeks(3)->subMinute(), now()->subHour()));
            $this->assertSame(LeafApplication::TYPE_DROP_OFF, $entry->application->type);
            $this->assertSame(LeafApplication::STATUS_COMPLETE, $entry->application->status);
            $this->assertTrue($entry->application->created_at->lt($entry->happened_at));
            $this->assertSame($entry->contact_id, $entry->application->contact_id);
            $this->assertSame($this->admin->id, $entry->contact->address()->located_by);
        }

        $unknown = ContactActivity::whereNull('contact_id')->get();
        $this->assertCount(2, $unknown);

        foreach ($unknown as $entry) {
            $this->assertTrue($entry->isUnknown());
            $this->assertContains($entry->drop_off_site_id, $sites->pluck('id')->all());
            $this->assertNull($entry->leaf_application_id);
        }
    }

    public function test_creates_twelve_with_three_unknown_by_default(): void
    {
        $this->artisan('leaves:seed-drop-offs')
            ->expectsOutputToContain('Created 12 drop-offs with no drop-off site, each for a new contact, and 3 drop-offs with no contact at the drop-off sites.')
            ->assertSuccessful();

        $this->assertSame(12, ContactActivity::whereNotNull('contact_id')->whereNull('drop_off_site_id')->count());
        $this->assertSame(3, ContactActivity::whereNull('contact_id')->count());
    }

    public function test_uses_the_drop_off_sites_already_there(): void
    {
        $north = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $south = Contact::create(['name' => 'South Drop-off Site', 'is_drop_off_site' => true]);

        $this->artisan('leaves:seed-drop-offs', ['count' => '0', '--unknown' => '5'])
            ->doesntExpectOutputToContain('Added test drop-off site')
            ->expectsOutputToContain('Created 5 drop-offs with no contact at the drop-off sites.')
            ->assertSuccessful();

        $this->assertSame(2, Contact::query()->dropOffSites()->count());
        $this->assertSame(0, LeafApplication::count());

        foreach (ContactActivity::all() as $entry) {
            $this->assertContains($entry->drop_off_site_id, [$north->id, $south->id]);
        }

        // One site already there needs only one more
        $south->delete();

        $this->artisan('leaves:seed-drop-offs', ['count' => '1', '--unknown' => '0'])
            ->expectsOutputToContain('Added test drop-off site: North Test Drop-off Site.')
            ->expectsOutputToContain('Created 1 drop-off with no drop-off site, for a new contact.')
            ->assertSuccessful();
    }

    public function test_needs_counts_from_0_to_200_and_at_least_one_drop_off(): void
    {
        foreach (['201', 'lots', '-3'] as $count) {
            $this->artisan('leaves:seed-drop-offs', ['count' => $count])
                ->expectsOutputToContain('Enter how many drop-offs with no drop-off site to create, from 0 to 200.')
                ->assertExitCode(SeedTestDropOffs::INVALID);

            $this->artisan('leaves:seed-drop-offs', ['--unknown' => $count])
                ->expectsOutputToContain('Enter how many drop-offs with no contact to create with --unknown, from 0 to 200.')
                ->assertExitCode(SeedTestDropOffs::INVALID);
        }

        $this->artisan('leaves:seed-drop-offs', ['count' => '0', '--unknown' => '0'])
            ->expectsOutputToContain('Enter at least one drop-off to create.')
            ->assertExitCode(SeedTestDropOffs::INVALID);

        $this->assertSame(0, ContactActivity::count());
        $this->assertSame(0, Contact::count());
    }

    public function test_needs_an_admin_to_log_the_drop_offs(): void
    {
        $this->admin->delete();

        $this->artisan('leaves:seed-drop-offs')
            ->expectsOutputToContain('Create an admin account first')
            ->assertFailed();

        $this->assertSame(0, ContactActivity::count());
    }

    public function test_refuses_on_a_server(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('leaves:seed-drop-offs')
            ->expectsOutputToContain("Test drop-offs can only be created on an admin's computer, never on a server.")
            ->assertFailed();

        $this->assertSame(0, ContactActivity::count());
    }
}
