<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Edit;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The append-only edits table, records' histories (RecordsEdits), and the
 * morph map naming their types.
 */
class EditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    /**
     * A note on a new contact's timeline.
     */
    private function note(): ContactActivity
    {
        $note = Contact::create(['name' => 'Jamie Rivera'])->activities()->make([
            'type' => ContactActivity::TYPE_NOTE,
            'happened_at' => now(),
            'body' => 'Uses paper bags',
        ]);
        $note->save();

        return $note;
    }

    public function test_types_are_stored_by_their_short_names(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame('contact_activity', (new ContactActivity)->getMorphClass());
        $this->assertSame('leaf_application', (new LeafApplication)->getMorphClass());
        $this->assertSame(ContactActivity::class, Relation::getMorphedModel('contact_activity'));
    }

    public function test_saving_records_only_the_changed_fields(): void
    {
        $note = $this->note();

        $note->body = 'Uses paper bags, always';
        $fields = $note->saveRecordingEdit($this->admin);

        $this->assertSame(['body' => ['from' => 'Uses paper bags', 'to' => 'Uses paper bags, always']], $fields);

        $edit = $note->edits()->sole();
        $this->assertSame(Edit::ACTION_EDITED, $edit->action);
        // Timestamps change on every save, so they're left out
        // jsonb doesn't keep key order, so compare without it
        $this->assertEquals($fields, $edit->fields);
        $this->assertTrue($edit->editedBy->is($this->admin));
        $this->assertTrue($edit->editable->is($note));
        $this->assertNull($edit->updated_at);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $note = $this->note();

        $this->assertSame([], $note->saveRecordingEdit($this->admin));
        $this->assertSame(0, Edit::count());
    }

    public function test_histories_are_newest_first_and_kept_after_deleting(): void
    {
        $note = $this->note();

        $note->recordEdit(Edit::ACTION_EDITED, ['body' => ['from' => 'a', 'to' => 'b']], $this->admin);
        $note->recordEdit(Edit::ACTION_DELETED, [], $this->admin);
        $note->delete();

        $this->assertSame([Edit::ACTION_DELETED, Edit::ACTION_EDITED], $note->edits()->pluck('action')->all());
        // Deleted records are still found from their edits
        $this->assertTrue(Edit::latest('id')->first()->editable->is($note));
    }

    public function test_admins_with_edits_cannot_be_hard_deleted(): void
    {
        $this->note()->recordEdit(Edit::ACTION_EDITED, [], $this->admin);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->admin->forceDelete();
    }
}
