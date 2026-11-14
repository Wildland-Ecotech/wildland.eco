<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\User;
use App\Reports\LeafLedgerReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin Leaf Ledger page and its CSV: logged pickups and drop-offs by
 * season, with totals. Also the bag totals and Leaf Ledger button on Leaf
 * Applications.
 */
class AdminLeafLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Sam Rivera']);
    }

    /**
     * A timeline entry for the contact, or with no contact, at a Fort Collins
     * time.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(?Contact $contact, string $type, string $when, ?int $bags = null, array $attributes = []): ContactActivity
    {
        $entry = new ContactActivity;
        $entry->forceFill([
            'contact_id' => $contact?->id,
            'type' => $type,
            // Stored in UTC, as the timeline form stores it
            'happened_at' => Carbon::parse($when, 'America/Denver')->utc(),
            'bag_count' => $bags,
            'logged_by' => $this->admin->id,
            ...$attributes,
        ])->save();

        return $entry;
    }

    /**
     * A pickup application with an estimated bag count and status.
     */
    private function application(int $bags, string $status = LeafApplication::STATUS_PENDING): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => $bags,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            'status' => $status,
        ])->save();

        return $application;
    }

    public function test_only_signed_in_admins_see_the_ledger(): void
    {
        $this->get('/admin/leaves/ledger')->assertRedirect(route('login'));
        $this->get('/admin/leaves/ledger/csv')->assertRedirect(route('login'));
    }

    public function test_lists_the_seasons_pickups_and_drop_offs_oldest_first_with_totals(): void
    {
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $alex = Contact::create(['name' => 'Alex Morgan']);
        $deleted = Contact::create(['name' => 'Spammy Spammer']);

        $this->entry($alex, ContactActivity::TYPE_DROP_OFF, '2026-11-08 09:19', 6);
        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2026-10-25 10:00', 14, ['body' => 'Had tape, removed']);
        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2026-11-12 15:00', 30, ['logged_by' => null]);

        // Left out: another season, other kinds of entries, deleted entries,
        // and entries of deleted contacts
        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2025-11-15 10:00', 9, ['body' => 'Last season']);
        $this->entry($jamie, ContactActivity::TYPE_TEXT, '2026-11-01 10:00', null, ['body' => 'Texted about bags', 'direction' => ContactActivity::DIRECTION_OUTGOING]);
        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2026-11-02 10:00', 7, ['body' => 'Logged by mistake'])->delete();
        $this->entry($deleted, ContactActivity::TYPE_PICKUP, '2026-11-03 10:00', 5);
        $deleted->delete();

        $response = $this->actingAs($this->admin)->get('/admin/leaves/ledger');

        $response->assertOk()
            ->assertSee('<option value="2026" selected>Autumn 2026</option>', false)
            ->assertSee('Autumn 2025</option>', false)
            ->assertSee('50 bags collected in Autumn 2026, from 2 pickups and 1 drop-off.')
            ->assertSeeInOrder(['Oct 25, 10:00 AM', 'Nov 8, 9:19 AM', 'Nov 12, 3:00 PM'])
            ->assertSee('<a href="' . route('admin.contacts.show', $jamie->id) . '">Jamie Rivera</a>', false)
            ->assertSee('Had tape, removed')
            ->assertSee('Drop-off')
            ->assertSee('Sam Rivera')
            ->assertSee(route('admin.leaves.ledger.csv', ['season' => 2026]))
            ->assertDontSee('Last season')
            ->assertDontSee('Texted about bags')
            ->assertDontSee('Logged by mistake')
            ->assertDontSee('Spammy Spammer');
    }

    public function test_drop_offs_with_no_contact_are_unknown(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
        $jamie = Contact::create(['name' => 'Jamie Rivera']);
        $deleted = Contact::create(['name' => 'Spammy Spammer']);

        $this->entry($jamie, ContactActivity::TYPE_PICKUP, '2026-11-02 10:00', 14);
        $this->entry(null, ContactActivity::TYPE_DROP_OFF, '2026-11-08 09:19', 6, ['drop_off_site_id' => $site->id, 'body' => 'By the gate']);
        // Entries of deleted contacts are still left out
        $this->entry($deleted, ContactActivity::TYPE_DROP_OFF, '2026-11-09 10:00', 5);
        $deleted->delete();

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger')
            ->assertOk()
            ->assertSee('20 bags collected in Autumn 2026, from 1 pickup and 1 drop-off.')
            ->assertSeeInOrder(['Nov 2, 10:00 AM', 'Jamie Rivera', 'Nov 8, 9:19 AM', 'Unknown', 'By the gate'])
            ->assertDontSee('>Unknown</a>', false)
            ->assertDontSee('Spammy Spammer');

        $this->assertStringContainsString(
            "\"2026-11-08 09:19\",Unknown,6,Drop-off,\"By the gate\",\"Sam Rivera\"\n",
            $this->actingAs($this->admin)->get('/admin/leaves/ledger/csv?season=2026')->getContent(),
        );
    }

    public function test_seasons_run_july_through_june(): void
    {
        $report = app(LeafLedgerReport::class);

        $this->assertSame(2025, $report->season(Carbon::parse('2026-06-30 23:30', 'America/Denver')));
        $this->assertSame(2026, $report->season(Carbon::parse('2026-07-01 00:30', 'America/Denver')));
        $this->assertSame(2026, $report->season(Carbon::parse('2027-02-15 12:00', 'America/Denver')));
        $this->assertSame(2026, $report->current());

        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $this->entry($contact, ContactActivity::TYPE_DROP_OFF, '2026-06-30 23:30', 4);
        $this->entry($contact, ContactActivity::TYPE_DROP_OFF, '2026-07-01 00:30', 8);

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger?season=2025')
            ->assertOk()
            ->assertSee('<option value="2025" selected>Autumn 2025</option>', false)
            ->assertSee('4 bags collected in Autumn 2025, from 0 pickups and 1 drop-off.');

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger?season=2026')
            ->assertSee('8 bags collected in Autumn 2026, from 0 pickups and 1 drop-off.');
    }

    public function test_an_empty_or_unknown_season_shows_the_current_one(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger?season=1999')
            ->assertOk()
            ->assertSee('<option value="2026" selected>Autumn 2026</option>', false)
            ->assertDontSee('Autumn 1999')
            ->assertSee('No pickups or drop-offs logged in Autumn 2026.');
    }

    public function test_the_csv_download_has_the_seasons_entries(): void
    {
        $contact = Contact::create(['name' => 'Jamie Rivera']);
        $this->entry($contact, ContactActivity::TYPE_PICKUP, '2026-10-25 10:00', 14, ['body' => 'Had tape, removed']);
        $this->entry($contact, ContactActivity::TYPE_PICKUP, '2025-11-15 10:00', 9);

        $response = $this->actingAs($this->admin)->get('/admin/leaves/ledger/csv?season=2026');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="leaf-ledger-2026.csv"');

        $this->assertSame(
            "Date,Name,Bags,Type,Notes,\"Logged by\"\n"
            . "\"2026-10-25 10:00\",\"Jamie Rivera\",14,Pickup,\"Had tape, removed\",\"Sam Rivera\"\n",
            $response->getContent(),
        );
    }

    public function test_leaf_applications_links_to_the_ledger_and_totals_bags(): void
    {
        $this->application(12);
        $this->application(8, LeafApplication::STATUS_COMPLETE);
        $this->application(30)->flag($this->admin, LeafApplication::FLAG_SPAM);

        $this->actingAs($this->admin)
            ->get('/admin/leaves/applications?spam=1')
            ->assertOk()
            ->assertSee('<a class="button dark small" href="' . route('admin.leaves.ledger') . '">Leaf Ledger</a>', false)
            ->assertSee('20 bags estimated, 12 still waiting for a pickup or drop-off.');

        $this->actingAs($this->admin)
            ->get('/admin/leaves/applications?type[]=drop_off')
            ->assertOk()
            ->assertDontSee('bags estimated');
    }
}
