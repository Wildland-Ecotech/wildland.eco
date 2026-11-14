<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timeline entries (ContactActivity), and how a pickup or drop-off marks its
 * application complete.
 */
class ContactActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->contact = Contact::create(['name' => 'Jamie Rivera']);
    }

    /**
     * A saved application, the contact's.
     */
    private function apply(string $type = LeafApplication::TYPE_PICKUP): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => $type,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now(),
        ]);
        $application->forceFill(['contact_id' => $this->contact->id])->save();

        return $application;
    }

    /**
     * An entry on the contact's timeline, logged by the admin.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes): ContactActivity
    {
        $activity = $this->contact->activities()->make(['happened_at' => now(), ...$attributes]);
        $activity->forceFill(['logged_by' => $this->admin->id])->save();

        return $activity;
    }

    public function test_new_applications_are_pending(): void
    {
        $application = $this->apply();

        $this->assertSame(LeafApplication::STATUS_PENDING, $application->status);
        $this->assertSame(LeafApplication::STATUS_PENDING, $application->fresh()->status);
        $this->assertSame([$application->id], LeafApplication::pending()->pluck('id')->all());
    }

    public function test_a_pickup_or_drop_off_completes_its_application(): void
    {
        $pickup = $this->apply();
        $dropOff = $this->apply(LeafApplication::TYPE_DROP_OFF);
        $other = $this->apply();

        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'bag_count' => 28, 'leaf_application_id' => $pickup->id]);
        $this->log(['type' => ContactActivity::TYPE_DROP_OFF, 'bag_count' => 12, 'leaf_application_id' => $dropOff->id]);

        $this->assertSame(LeafApplication::STATUS_COMPLETE, $pickup->fresh()->status);
        $this->assertSame(LeafApplication::STATUS_COMPLETE, $dropOff->fresh()->status);
        // Only the application it was for
        $this->assertSame([$other->id], LeafApplication::pending()->pluck('id')->all());
    }

    public function test_other_entries_leave_the_application_pending(): void
    {
        $application = $this->apply();

        $this->log(['type' => ContactActivity::TYPE_TEXT, 'direction' => ContactActivity::DIRECTION_OUTGOING, 'body' => 'Are your bags ready?', 'leaf_application_id' => $application->id]);
        // A pickup for no application in particular
        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'bag_count' => 5]);

        $this->assertSame(LeafApplication::STATUS_PENDING, $application->fresh()->status);
    }

    public function test_an_entry_knows_its_contact_application_and_admin(): void
    {
        $application = $this->apply();
        $activity = $this->log(['type' => ContactActivity::TYPE_PICKUP, 'bag_count' => 28, 'leaf_application_id' => $application->id]);
        $deleted = $this->log(['type' => ContactActivity::TYPE_NOTE, 'body' => 'Logged by mistake']);
        $deleted->delete();

        $activity->refresh();
        $this->assertTrue($activity->contact->is($this->contact));
        $this->assertTrue($activity->application->is($application));
        $this->assertTrue($activity->loggedBy->is($this->admin));
        $this->assertSame(28, $activity->bag_count);
        // Deleted entries are kept but left off the timeline
        $this->assertSame([$activity->id], $this->contact->activities()->pluck('id')->all());
        $this->assertSame([$activity->id], $application->activities()->pluck('id')->all());
        $this->assertNotNull(ContactActivity::withTrashed()->find($deleted->id));
    }

    /**
     * A drop-off with no contact, left at a new drop-off site and logged by
     * the admin.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function logUnknown(array $attributes = []): ContactActivity
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);

        $activity = new ContactActivity;
        $activity->forceFill([
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => now(),
            'bag_count' => 6,
            'drop_off_site_id' => $site->id,
            'logged_by' => $this->admin->id,
            ...$attributes,
        ])->save();

        return $activity;
    }

    public function test_a_drop_off_can_have_no_contact_yet(): void
    {
        $activity = $this->logUnknown(['bag_brand' => 'Ace Hardware'])->refresh();

        $this->assertTrue($activity->isUnknown());
        $this->assertNull($activity->contact);
        $this->assertSame('North Drop-off Site', $activity->dropOffSite->name);
        $this->assertSame('Ace Hardware', $activity->bag_brand);
        $this->assertFalse($this->log(['type' => ContactActivity::TYPE_NOTE, 'body' => 'Call back'])->isUnknown());
    }

    public function test_the_database_refuses_pickups_with_no_contact(): void
    {
        $this->expectException(QueryException::class);

        $this->logUnknown(['type' => ContactActivity::TYPE_PICKUP, 'drop_off_site_id' => null]);
    }

    public function test_the_database_refuses_drop_offs_with_no_contact_or_drop_off_site(): void
    {
        $this->expectException(QueryException::class);

        $this->logUnknown(['drop_off_site_id' => null]);
    }

    public function test_the_database_refuses_drop_offs_for_an_application_with_no_contact(): void
    {
        $application = $this->apply(LeafApplication::TYPE_DROP_OFF);

        $this->expectException(QueryException::class);

        $this->logUnknown(['leaf_application_id' => $application->id]);
    }

    public function test_the_database_allows_a_drop_off_site_only_on_drop_offs(): void
    {
        $site = Contact::create(['name' => 'North Drop-off Site', 'is_drop_off_site' => true]);

        $this->expectException(QueryException::class);

        $this->log(['type' => ContactActivity::TYPE_PICKUP, 'bag_count' => 6, 'drop_off_site_id' => $site->id]);
    }

    public function test_reaching_them_means_a_text_or_call_from_us_or_a_visit(): void
    {
        $this->assertTrue($this->log(['type' => ContactActivity::TYPE_TEXT, 'direction' => ContactActivity::DIRECTION_OUTGOING])->reachedThem());
        $this->assertTrue($this->log(['type' => ContactActivity::TYPE_CALL, 'direction' => ContactActivity::DIRECTION_OUTGOING])->reachedThem());
        $this->assertTrue($this->log(['type' => ContactActivity::TYPE_VISIT])->reachedThem());
        $this->assertFalse($this->log(['type' => ContactActivity::TYPE_TEXT, 'direction' => ContactActivity::DIRECTION_INCOMING])->reachedThem());
        $this->assertFalse($this->log(['type' => ContactActivity::TYPE_NOTE, 'body' => 'Has a big dog'])->reachedThem());
    }
}
