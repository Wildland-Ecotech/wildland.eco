<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Edit;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Reports\FollowUpReport;
use App\Reports\MapReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Withdrawing leaf applications from their admin page, with a reason and a
 * note, and reopening them; withdrawn applications stay listed but leave
 * everything built on pending applications.
 */
class ApplicationWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-17 16:28', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Rachel Fish']);
        $this->contact = Contact::create(['name' => 'Kole Schuster']);
    }

    /**
     * A pending pickup application of the contact's, made yesterday.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(array $attributes = []): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Kole Schuster',
            'email' => 'kole@example.com',
            'phone' => '(970) 555-0472',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 55,
            'ready_now' => true,
            'terms_accepted_at' => now()->subDay(),
            'contact_id' => $this->contact->id,
            'created_at' => now()->subDay(),
            ...$attributes,
        ])->save();

        return $application;
    }

    /**
     * Withdraw the application as the admin.
     *
     * @param  array<string, mixed>  $input
     */
    private function withdraw(LeafApplication $application, array $input = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.leaves.withdraw', $application->id), [
            'reason' => LeafApplication::WITHDRAWN_NOT_ELIGIBLE,
            'withdrawn_note' => 'Used plastic bags',
            ...$input,
        ]);
    }

    public function test_only_signed_in_admins_can_withdraw_or_reopen(): void
    {
        $application = $this->application();

        $this->post(route('admin.leaves.withdraw', $application->id))->assertRedirect(route('login'));
        $this->post(route('admin.leaves.reopen', $application->id))->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->post(route('admin.leaves.withdraw', $application->id), ['reason' => LeafApplication::WITHDRAWN_OTHER])->assertForbidden();
        $this->actingAs($donor)->post(route('admin.leaves.reopen', $application->id))->assertForbidden();

        $this->assertSame(LeafApplication::STATUS_PENDING, $application->refresh()->status);
    }

    public function test_a_pending_application_has_a_withdraw_form(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin)
            ->get(route('admin.leaves.show', $application->id))
            // The legend is template text; the reasons are escaped
            ->assertSeeInOrder(['<h2 id="withdraw">Withdraw</h2>', "Why it's withdrawn", e("Didn't meet requirements"), 'Replaced by a newer application', 'Took leaves elsewhere', e("Couldn't reach them"), 'No longer donating', 'Other', 'Note (optional, required for Other)', 'Withdraw Application'], false)
            ->assertDontSee('Reopen Application');
    }

    public function test_withdraws_with_a_reason_and_note(): void
    {
        $application = $this->application();

        $this->withdraw($application)
            ->assertRedirect(route('admin.leaves.show', $application->id))
            ->assertSessionHas('status', "Withdrew application {$application->id}.");

        $application->refresh();
        $this->assertTrue($application->isWithdrawn());
        $this->assertSame(LeafApplication::WITHDRAWN_NOT_ELIGIBLE, $application->withdrawn_reason);
        $this->assertSame('Used plastic bags', $application->withdrawn_note);
        $this->assertTrue($application->withdrawn_at->eq(now()));
        $this->assertSame($this->admin->id, $application->withdrawn_by);
        // Kept, unlike test and spam applications
        $this->assertNotSoftDeleted($application);

        $edit = $application->edits()->sole();
        $this->assertSame(Edit::ACTION_WITHDRAWN, $edit->action);
        // jsonb doesn't keep key order, so compare without it
        $this->assertEquals(['from' => 'pending', 'to' => 'withdrawn'], $edit->fields['status']);
        $this->assertEquals(['from' => null, 'to' => 'not_eligible'], $edit->fields['withdrawn_reason']);

        $this->actingAs($this->admin)
            ->get(route('admin.leaves.show', $application->id))
            // The note is shown as typed
            ->assertSee("Withdrawn by Rachel Fish on Nov 17, 2026 4:28 PM MST: Didn't meet requirements. Used plastic bags")
            ->assertSee("Withdrawn · Didn't meet requirements")
            ->assertSee('<h2 id="withdraw">Withdrawn</h2>', false)
            ->assertSee('Reopen Application')
            ->assertDontSee('Withdraw Application');
    }

    public function test_other_needs_a_note_and_a_reason_is_required(): void
    {
        $application = $this->application();

        $this->withdraw($application, ['reason' => LeafApplication::WITHDRAWN_OTHER, 'withdrawn_note' => ''])
            ->assertRedirect(route('admin.leaves.show', $application->id) . '#withdraw')
            ->assertSessionHasErrors(['withdrawn_note' => 'Add a note saying what happened, since the reason is Other.']);

        $this->withdraw($application, ['reason' => 'bored'])
            ->assertSessionHasErrors(['reason' => 'Choose why the application is withdrawn.']);

        $this->withdraw($application, ['withdrawn_note' => str_repeat('a', 2001)])
            ->assertSessionHasErrors(['withdrawn_note' => 'Keep the note to 2,000 characters or fewer.']);

        $this->assertSame(LeafApplication::STATUS_PENDING, $application->refresh()->status);

        // Without a note for the other reasons
        $this->withdraw($application, ['reason' => LeafApplication::WITHDRAWN_UNREACHABLE, 'withdrawn_note' => ''])->assertSessionHasNoErrors();
        $this->assertNull($application->refresh()->withdrawn_note);
    }

    public function test_only_pending_applications_can_be_withdrawn(): void
    {
        $application = $this->application(['status' => LeafApplication::STATUS_COMPLETE]);

        $this->withdraw($application)
            ->assertSessionHas('status', "Application {$application->id} isn't pending, so it can't be withdrawn.");

        $this->assertSame(LeafApplication::STATUS_COMPLETE, $application->refresh()->status);

        $this->actingAs($this->admin)
            ->get(route('admin.leaves.show', $application->id))
            ->assertDontSee('<h2 id="withdraw">', false);
    }

    public function test_reopening_puts_it_back_to_pending(): void
    {
        $application = $this->application();
        $application->withdraw($this->admin, LeafApplication::WITHDRAWN_WENT_ELSEWHERE, 'Brought them to the city yard');

        $this->actingAs($this->admin)
            ->post(route('admin.leaves.reopen', $application->id))
            ->assertRedirect(route('admin.leaves.show', $application->id))
            ->assertSessionHas('status', "Reopened application {$application->id}.");

        $application->refresh();
        $this->assertSame(LeafApplication::STATUS_PENDING, $application->status);
        $this->assertNull($application->withdrawn_reason);
        $this->assertNull($application->withdrawn_note);
        $this->assertNull($application->withdrawn_at);
        $this->assertNull($application->withdrawn_by);
        // The withdrawal stays on record
        $this->assertSame([Edit::ACTION_REOPENED, Edit::ACTION_WITHDRAWN], $application->edits()->pluck('action')->all());

        $this->actingAs($this->admin)
            ->post(route('admin.leaves.reopen', $application->id))
            ->assertSessionHas('status', "Application {$application->id} isn't withdrawn.");
    }

    public function test_withdrawn_applications_leave_everything_built_on_pending_ones(): void
    {
        $this->contact->moveTo('123 Example St, Fort Collins, CO 80521')->locate(40.57, -105.08, $this->admin);
        $application = $this->application();
        $application->withdraw($this->admin, LeafApplication::WITHDRAWN_REPLACED, 'New application with 40 bags');

        $this->assertSame(0, LeafApplication::pending()->count());
        $this->assertTrue(app(FollowUpReport::class)->pending()->isEmpty());
        $this->assertTrue(app(MapReport::class)->pickups()->isEmpty());

        // Its contact keeps a record of who applied
        $this->actingAs($this->admin)
            ->get(route('admin.contacts.edit', $this->contact->id))
            ->assertSee("Contacts with applications can't be deleted.");

        $this->actingAs($this->admin)
            ->get(route('admin.contacts.show', $this->contact->id))
            ->assertSeeInOrder(['Nov 17, 4:28 PM', 'Withdrawn: Replaced by a newer application · Rachel Fish', 'New application with 40 bags', "Application {$application->id}"])
            ->assertSee('Withdrawn');
    }

    public function test_planned_loads_show_withdrawn_stops(): void
    {
        $this->contact->moveTo('123 Example St, Fort Collins, CO 80521');
        $application = $this->application();
        $load = new TrailerLoad(['planned_on' => '2026-11-20', 'capacity' => 80]);
        $load->forceFill(['status' => TrailerLoad::STATUS_PLANNED, 'created_by' => $this->admin->id])->save();
        $load->applications()->attach($application->id, ['position' => 1]);

        $this->actingAs($this->admin)
            ->get("/admin/map?load={$load->id}")
            ->assertDontSee('<th scope="col">Pickup</th>', false);

        $application->withdraw($this->admin, LeafApplication::WITHDRAWN_WENT_ELSEWHERE);

        $this->actingAs($this->admin)
            ->get("/admin/map?load={$load->id}")
            ->assertSee('<th scope="col">Pickup</th>', false)
            ->assertSee('<td>Withdrawn</td>', false)
            ->assertSee('<p class="stop-pickup">Withdrawn</p>', false);
    }

    public function test_leaf_applications_badge_count_and_filter_withdrawn_ones(): void
    {
        $this->application(['name' => 'Elvera Graham', 'estimated_bag_count' => 22]);
        $this->application(['name' => 'Kole Schuster'])->withdraw($this->admin, LeafApplication::WITHDRAWN_NOT_ELIGIBLE);
        $this->application(['name' => 'Elmira Abshire', 'estimated_bag_count' => 30])->withdraw($this->admin, LeafApplication::WITHDRAWN_WENT_ELSEWHERE);
        $this->application(['name' => 'Sam Lee', 'estimated_bag_count' => 30])->withdraw($this->admin, LeafApplication::WITHDRAWN_WENT_ELSEWHERE);

        $this->actingAs($this->admin)
            ->get('/admin/leaves/applications')
            ->assertSee('<span class="badge muted">Withdrawn</span> Took leaves elsewhere', false)
            ->assertSee('<span class="badge muted">Withdrawn</span> ' . e("Didn't meet requirements"), false)
            ->assertSee("3 withdrawn: 2 took leaves elsewhere, 1 didn't meet requirements.")
            // Withdrawn bags aren't counted
            ->assertSee('22 bags estimated, 22 still waiting for a pickup or drop-off.')
            ->assertSee('<input type="checkbox" name="withdrawn" value="1" >', false)
            ->assertSee('Elvera Graham');

        $this->actingAs($this->admin)
            ->get('/admin/leaves/applications?withdrawn=1')
            ->assertSee('<input type="checkbox" name="withdrawn" value="1" checked>', false)
            ->assertSee('3 applications in the last 30 days.')
            ->assertDontSee('Elvera Graham')
            ->assertSee(e(route('admin.leaves.applications.csv', ['period' => '30', 'withdrawn' => '1'])), false);

        $csv = $this->actingAs($this->admin)->get('/admin/leaves/applications/csv?withdrawn=1')->getContent();
        $this->assertStringContainsString('"Withdrawn: Took leaves elsewhere"', $csv);
        $this->assertStringNotContainsString('Elvera Graham', $csv);
    }

    public function test_the_csv_and_leaves_view_show_the_status(): void
    {
        $pending = $this->application(['name' => 'Elvera Graham']);
        $withdrawn = $this->application();
        $withdrawn->withdraw($this->admin, LeafApplication::WITHDRAWN_UNREACHABLE, 'Three texts, no reply');

        $rows = array_map('str_getcsv', array_filter(explode("\n", $this->actingAs($this->admin)->get('/admin/leaves/applications/csv')->getContent())));

        $this->assertSame('Status', $rows[0][2]);
        $this->assertSame(['Pending', "Withdrawn: Couldn't reach them"], [$rows[1][2], $rows[2][2]]);

        $this->artisan('leaves:view', ['application' => $withdrawn->id])
            ->expectsOutputToContain('Withdrawn by Rachel Fish on Nov 17, 2026 4:28 PM MST')
            ->assertSuccessful();
    }

    public function test_the_database_needs_a_reason_on_withdrawn_applications_only(): void
    {
        $application = $this->application();

        $this->expectException(QueryException::class);

        $application->forceFill(['status' => LeafApplication::STATUS_WITHDRAWN])->save();
    }
}
