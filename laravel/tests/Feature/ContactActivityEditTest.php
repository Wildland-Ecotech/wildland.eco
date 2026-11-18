<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Edit;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Editing and deleting timeline entries (/admin/activities/{id}/edit), each
 * change recorded in the edits table, with applications following their
 * pickups and drop-offs.
 */
class ContactActivityEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $contact;

    private Contact $site;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-17 15:00', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create(['name' => 'Rachel Fish']);
        $this->contact = Contact::create(['name' => 'Jamie Rivera']);
        $this->site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);
    }

    /**
     * A drop-off of 24 bags for the contact on Nov 10 at 9:30:15 AM, with
     * no drop-off site, as logged before sites were recorded.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes = []): ContactActivity
    {
        $entry = new ContactActivity;
        $entry->forceFill([
            'contact_id' => $this->contact->id,
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => Carbon::parse('2026-11-10 09:30:15', 'America/Denver')->utc(),
            'bag_count' => 24,
            'logged_by' => $this->admin->id,
            ...$attributes,
        ])->save();

        return $entry;
    }

    /**
     * A pending drop-off application of the contact's.
     */
    private function application(?Contact $contact = null): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 24,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            'contact_id' => ($contact ?? $this->contact)->id,
        ])->save();

        return $application;
    }

    /**
     * Save the entry as the admin, with its values unless changed.
     *
     * @param  array<string, mixed>  $input
     */
    private function save(ContactActivity $entry, array $input = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.activities.update', $entry->id), [
            'contact_id' => $entry->contact_id,
            'type' => $entry->type,
            'happened_at' => '2026-11-10T09:30',
            'bag_count' => (string) $entry->bag_count,
            'drop_off_site_id' => $this->site->id,
            'leaf_application_id' => $entry->leaf_application_id,
            ...$input,
        ]);
    }

    public function test_only_signed_in_admins_can_edit(): void
    {
        $entry = $this->entry();

        $this->get(route('admin.activities.edit', $entry->id))->assertRedirect(route('login'));
        $this->post(route('admin.activities.update', $entry->id))->assertRedirect(route('login'));
        $this->post(route('admin.activities.destroy', $entry->id))->assertRedirect(route('login'));

        $donor = User::factory()->withPassword()->create();
        $this->actingAs($donor)->get(route('admin.activities.edit', $entry->id))->assertForbidden();
        $this->actingAs($donor)->post(route('admin.activities.update', $entry->id))->assertForbidden();
        $this->actingAs($donor)->post(route('admin.activities.destroy', $entry->id), ['confirm' => '1'])->assertForbidden();

        $this->assertNotSoftDeleted($entry);
    }

    public function test_the_edit_page_is_filled_with_the_entry(): void
    {
        $entry = $this->entry(['bag_brand' => 'Ace Hardware', 'body' => 'By the gate']);
        // Contacts with the same name are told apart by ID
        $other = Contact::create(['name' => 'Jamie Rivera']);

        $this->actingAs($this->admin)
            ->from(route('admin.leaves.ledger', ['season' => 2026]))
            ->get(route('admin.activities.edit', $entry->id))
            ->assertOk()
            ->assertSee('<h1>Edit Entry</h1>', false)
            ->assertSee('aria-current="page">Contacts</a>', false)
            ->assertSee('Logged Nov 17, 3:00 PM by Rachel Fish.')
            ->assertSee('<option value="" >Unknown</option>', false)
            ->assertSee('<option value="' . $this->contact->id . '" selected>Jamie Rivera (#' . $this->contact->id . ')</option>', false)
            ->assertSee('<option value="' . $other->id . '" >Jamie Rivera (#' . $other->id . ')</option>', false)
            ->assertSee('<input type="radio" name="type" value="drop_off" checked>', false)
            ->assertSee('value="2026-11-10T09:30"', false)
            ->assertSee('>By the gate</textarea>', false)
            ->assertSee('value="24"', false)
            ->assertSee('value="Ace Hardware"', false)
            // No site recorded yet
            ->assertSee('<option value="">Choose a site</option>', false)
            ->assertSee('<input type="hidden" name="return_to" value="/admin/leaves/ledger?season=2026">', false)
            ->assertSee('href="/admin/leaves/ledger?season=2026">Cancel</a>', false)
            ->assertSee('No edits yet.')
            ->assertSee('<h2 id="delete">Delete Entry</h2>', false);
    }

    public function test_drop_offs_with_no_contact_are_under_leaves(): void
    {
        $entry = $this->entry(['contact_id' => null, 'drop_off_site_id' => $this->site->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.activities.edit', $entry->id))
            ->assertSee('aria-current="page">Leaves</a>', false)
            ->assertSee('<option value="" selected>Unknown</option>', false)
            // Back to the ledger without a page to go back to
            ->assertSee('href="' . route('admin.leaves.ledger', ['season' => 2026]) . '">Cancel</a>', false);
    }

    public function test_saving_records_the_edit_and_goes_back(): void
    {
        $entry = $this->entry();

        $this->save($entry, ['return_to' => '/admin/contacts/review'])
            ->assertRedirect('/admin/contacts/review')
            ->assertSessionHas('status', 'Saved the drop-off of 24 bags from Nov 10.');

        $entry->refresh();
        $this->assertSame($this->site->id, $entry->drop_off_site_id);
        // The minute didn't change, so the stored seconds are kept
        $this->assertSame(15, $entry->happened_at->second);

        $edit = Edit::sole();
        $this->assertSame('contact_activity', $edit->editable_type);
        $this->assertSame($entry->id, $edit->editable_id);
        $this->assertSame(Edit::ACTION_EDITED, $edit->action);
        $this->assertSame($this->admin->id, $edit->edited_by);
        // jsonb doesn't keep key order, so compare without it
        $this->assertEquals(['drop_off_site_id' => ['from' => null, 'to' => $this->site->id]], $edit->fields);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $entry = $this->entry(['drop_off_site_id' => $this->site->id]);

        // Without a page to go back to, it goes to the contact
        $this->save($entry)
            ->assertRedirect(route('admin.contacts.show', $this->contact->id))
            ->assertSessionHas('status', 'Nothing changed.');

        $this->assertSame(0, Edit::count());
    }

    public function test_every_drop_off_needs_a_site_older_ones_included(): void
    {
        $entry = $this->entry();

        $this->save($entry, ['drop_off_site_id' => ''])
            ->assertSessionHasErrors(['drop_off_site_id' => 'Choose the drop-off site the bags were left at.']);

        $this->assertNull($entry->refresh()->drop_off_site_id);
        $this->assertSame(0, Edit::count());
    }

    public function test_choosing_a_contact_assigns_unknown_bags(): void
    {
        $entry = $this->entry(['contact_id' => null, 'drop_off_site_id' => $this->site->id]);

        $this->save($entry, ['contact_id' => $this->contact->id])
            ->assertRedirect(route('admin.contacts.show', $this->contact->id));

        $entry->refresh();
        $this->assertSame($this->contact->id, $entry->contact_id);
        $this->assertSame($this->admin->id, $entry->assigned_by);
        $this->assertTrue($entry->assigned_at->eq(now()));
        $this->assertSame(Edit::ACTION_ASSIGNED, Edit::sole()->action);
        $this->assertEquals(['from' => null, 'to' => $this->contact->id], Edit::sole()->fields['contact_id']);

        // Back to Unknown clears who assigned it; the history keeps both
        $this->save($entry, ['contact_id' => ''])
            ->assertRedirect(route('admin.leaves.ledger', ['season' => 2026]));

        $entry->refresh();
        $this->assertNull($entry->contact_id);
        $this->assertNull($entry->assigned_at);
        $this->assertNull($entry->assigned_by);
        $this->assertSame([Edit::ACTION_EDITED, Edit::ACTION_ASSIGNED], $entry->edits()->pluck('action')->all());
    }

    public function test_only_drop_offs_with_no_application_can_be_unknown(): void
    {
        $pickup = $this->entry(['type' => ContactActivity::TYPE_PICKUP]);

        $this->save($pickup, ['contact_id' => ''])
            ->assertSessionHasErrors(['contact_id' => 'Choose a contact. Only drop-offs can be Unknown.']);

        $application = $this->application();
        $dropOff = $this->entry(['drop_off_site_id' => $this->site->id]);

        $this->save($dropOff, ['contact_id' => '', 'leaf_application_id' => $application->id])
            ->assertSessionHasErrors(['leaf_application_id']);

        $this->assertSame($this->contact->id, $pickup->refresh()->contact_id);
        $this->assertSame($this->contact->id, $dropOff->refresh()->contact_id);
    }

    public function test_applications_of_other_contacts_are_refused(): void
    {
        $entry = $this->entry();
        $other = $this->application(Contact::create(['name' => 'Sam Lee']));

        $this->save($entry, ['leaf_application_id' => $other->id])
            ->assertSessionHasErrors(['leaf_application_id' => "Choose one of this contact's applications, or None."]);
    }

    public function test_return_to_must_be_an_admin_page(): void
    {
        $entry = $this->entry();

        $this->save($entry, ['return_to' => 'https://example.com/admin'])
            ->assertSessionHasErrors(['return_to' => 'Open the entry from its contact or the Leaf Ledger, then save again.']);
    }

    public function test_applications_follow_their_pickups_and_drop_offs(): void
    {
        $first = $this->application();
        $entry = $this->entry(['leaf_application_id' => $first->id, 'drop_off_site_id' => $this->site->id]);
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $first->refresh()->status);

        // Left with no drop-off, it goes back to pending, recorded on the application
        $this->save($entry, ['leaf_application_id' => ''])->assertSessionHasNoErrors();
        $this->assertSame(LeafApplication::STATUS_PENDING, $first->refresh()->status);
        $this->assertEquals(['status' => ['from' => 'complete', 'to' => 'pending']], $first->edits()->sole()->fields);
        $this->assertSame($this->admin->id, $first->edits()->sole()->edited_by);

        // Chosen again, it's complete, recorded on the application
        $second = $this->application();
        $this->save($entry, ['leaf_application_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $second->refresh()->status);
        $this->assertEquals(['status' => ['from' => 'pending', 'to' => 'complete']], $second->edits()->sole()->fields);

        // Another drop-off for it keeps it complete
        $other = $this->entry(['leaf_application_id' => $second->id, 'drop_off_site_id' => $this->site->id]);
        $this->save($entry, ['type' => ContactActivity::TYPE_NOTE, 'body' => 'Wrong entry', 'leaf_application_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $second->refresh()->status);

        // Deleting the last one puts it back to pending
        $this->actingAs($this->admin)->post(route('admin.activities.destroy', $other->id), ['confirm' => '1']);
        $this->assertSame(LeafApplication::STATUS_PENDING, $second->refresh()->status);
    }

    public function test_deleting_needs_the_box_and_records_the_deletion(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin)
            ->post(route('admin.activities.destroy', $entry->id))
            ->assertSessionHasErrors(['confirm' => 'Check the box to confirm, then press Delete Entry.']);

        $this->assertNotSoftDeleted($entry);

        $this->actingAs($this->admin)
            ->post(route('admin.activities.destroy', $entry->id), ['confirm' => '1', 'return_to' => '/admin/leaves/ledger?season=2026'])
            ->assertRedirect('/admin/leaves/ledger?season=2026')
            ->assertSessionHas('status', 'Deleted the drop-off of 24 bags from Nov 10.');

        $this->assertSoftDeleted($entry);
        $edit = Edit::sole();
        $this->assertSame(Edit::ACTION_DELETED, $edit->action);
        $this->assertSame([], $edit->fields);
        $this->assertTrue($edit->editable->is($entry));

        $this->actingAs($this->admin)->get(route('admin.activities.edit', $entry->id))->assertNotFound();
    }

    public function test_the_history_lists_each_edit(): void
    {
        $entry = $this->entry();
        $this->save($entry, ['bag_count' => '22']);

        $this->actingAs($this->admin)
            ->get(route('admin.activities.edit', $entry->id))
            ->assertSeeInOrder(['<h2 id="history">History</h2>', 'Nov 17, 3:00 PM', 'Edited by Rachel Fish', 'Bags: 24 → 22', 'Drop-off site: none → North Drop-off Site'], false)
            ->assertDontSee('No edits yet.');
    }

    public function test_the_timeline_links_to_the_edit_page_and_marks_edited_entries(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin)
            ->get(route('admin.contacts.show', $this->contact->id))
            ->assertSee('<a href="' . route('admin.activities.edit', $entry->id) . '">Edit</a>', false)
            ->assertDontSee('· Edited');

        $this->save($entry);

        $this->actingAs($this->admin)
            ->get(route('admin.contacts.show', $this->contact->id))
            ->assertSee('Nov 10, 9:30 AM · Edited');
    }

    public function test_the_ledger_opens_each_entry_to_edit(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin)
            ->get('/admin/leaves/ledger')
            ->assertSee('<a class="record-link" href="' . route('admin.activities.edit', $entry->id) . '">Nov 10, 9:30 AM</a>', false)
            ->assertSee('Edit Entry');
    }

    public function test_the_application_menu_lists_pending_applications_and_the_one_its_for(): void
    {
        $pending = $this->application();
        $done = $this->application();
        $entry = $this->entry(['leaf_application_id' => $done->id, 'drop_off_site_id' => $this->site->id]);
        $withdrawn = $this->application();
        $withdrawn->withdraw($this->admin, LeafApplication::WITHDRAWN_REPLACED);

        $this->actingAs($this->admin)
            ->get(route('admin.activities.edit', $entry->id))
            ->assertSee('data-contact-select', false)
            ->assertSee('<option value="' . $done->id . '" selected>' . $done->id . ' · Drop-off', false)
            ->assertSee('<option value="' . $pending->id . '" >' . $pending->id . ' · Drop-off', false)
            ->assertSee(' · complete</option>', false)
            ->assertDontSee('<option value="' . $withdrawn->id . '"', false)
            ->assertSee('<p class="hint" data-contact-changed-hint hidden>Applications of the new contact can be chosen after saving.</p>', false);
    }
}
