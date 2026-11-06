<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Reports\FollowUpReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The order to follow up with pending applications (FollowUpReport): who's
 * waiting on us, whose bags are ready, who we've reached, and who can wait.
 */
class FollowUpReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'America/Denver'));
    }

    /**
     * A pending application made $daysAgo, with its own contact unless
     * $linked is false.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function apply(string $name, int $daysAgo, array $overrides = [], bool $linked = true): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $name,
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now()->subDays($daysAgo),
            'contact_id' => $linked ? Contact::create(['name' => $name])->id : null,
            'created_at' => now()->subDays($daysAgo),
            ...$overrides,
        ])->save();

        return $application;
    }

    /**
     * A text, call, visit, or note on the application's contact's timeline,
     * $daysAgo.
     */
    private function log(LeafApplication $application, string $type, ?string $direction, int $daysAgo): void
    {
        $application->contact->activities()->create([
            'type' => $type,
            'direction' => $direction,
            'happened_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * A Fort Collins date $days from today.
     */
    private function date(int $days): string
    {
        return Carbon::today('America/Denver')->addDays($days)->toDateString();
    }

    /**
     * Each application's reason, by name, in the report's order.
     *
     * @return array<string, string>
     */
    private function reasons(): array
    {
        return app(FollowUpReport::class)->pending()
            ->mapWithKeys(fn (array $item) => [$item['application']->name => $item['reason']])
            ->all();
    }

    public function test_each_application_gets_the_first_reason_that_fits(): void
    {
        $this->apply('New, ready now', 1);
        $this->apply('Unlinked', 1, linked: false);
        $this->apply('A week without hearing from us', 8);
        $this->apply('Bags ready next month', 2, ['ready_now' => false, 'ready_date' => $this->date(30)]);
        $this->apply('Bags ready in five days', 2, ['ready_now' => false, 'ready_date' => $this->date(5)]);
        // A week without hearing from us comes first, even with bags not ready yet
        $this->apply('Ready next month, a week waiting', 10, ['ready_now' => false, 'ready_date' => $this->date(30)]);

        $texted = $this->apply('Texted', 8);
        $this->log($texted, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 6);

        $visited = $this->apply('Visited', 8);
        $this->log($visited, ContactActivity::TYPE_VISIT, null, 1);

        $replied = $this->apply('They replied', 3);
        $this->log($replied, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 2);
        $this->log($replied, ContactActivity::TYPE_CALL, ContactActivity::DIRECTION_INCOMING, 1);

        // Notes don't count as reaching them
        $noted = $this->apply('Only a note', 8);
        $this->log($noted, ContactActivity::TYPE_NOTE, null, 1);

        // Nor does a text from before they applied
        $before = $this->apply('Texted before applying', 2);
        $this->log($before, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 5);

        $later = $this->apply('Check back next week', 10, ['follow_up_on' => $this->date(5)]);
        $this->log($later, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 9);

        $due = $this->apply('Check back today', 10, ['follow_up_on' => $this->date(0)]);
        $this->log($due, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 9);

        $done = $this->apply('Checked back', 10, ['follow_up_on' => $this->date(-1)]);
        $this->log($done, ContactActivity::TYPE_CALL, ContactActivity::DIRECTION_OUTGOING, 0);

        $reasons = $this->reasons();
        ksort($reasons);

        $this->assertSame([
            'A week without hearing from us' => FollowUpReport::NOT_REACHED,
            'Bags ready in five days' => FollowUpReport::READY,
            'Bags ready next month' => FollowUpReport::READY_LATER,
            'Check back next week' => FollowUpReport::FOLLOW_UP_LATER,
            'Check back today' => FollowUpReport::FOLLOW_UP_DUE,
            'Checked back' => FollowUpReport::CONTACTED,
            'New, ready now' => FollowUpReport::READY,
            'Only a note' => FollowUpReport::NOT_REACHED,
            'Ready next month, a week waiting' => FollowUpReport::NOT_REACHED,
            'Texted' => FollowUpReport::CONTACTED,
            'Texted before applying' => FollowUpReport::READY,
            'They replied' => FollowUpReport::UNANSWERED,
            'Unlinked' => FollowUpReport::READY,
            'Visited' => FollowUpReport::CONTACTED,
        ], $reasons);
    }

    public function test_groups_are_listed_in_order_each_oldest_first(): void
    {
        $this->apply('Later', 3, ['ready_now' => false, 'ready_date' => $this->date(30)]);
        $this->apply('Ready, newer', 2);
        $this->apply('Ready, older', 4);
        $this->apply('Waiting', 9);
        $contacted = $this->apply('Contacted', 5);
        $this->log($contacted, ContactActivity::TYPE_TEXT, ContactActivity::DIRECTION_OUTGOING, 4);
        // Left out: complete and deleted applications
        $this->apply('Picked up', 20, ['status' => LeafApplication::STATUS_COMPLETE]);
        $this->apply('Deleted', 20)->delete();

        $report = app(FollowUpReport::class)->pending();

        $this->assertSame(['Waiting', 'Ready, older', 'Ready, newer', 'Contacted', 'Later'], $report->map(fn (array $item) => $item['application']->name)->all());
        $this->assertSame([FollowUpReport::WAITING, FollowUpReport::READY, FollowUpReport::READY, FollowUpReport::CONTACTED, FollowUpReport::LATER], $report->pluck('tier')->all());
        // When we last reached them
        $this->assertTrue(now()->subDays(4)->eq($report[3]['last_reached_at']));
        $this->assertNull($report[0]['last_reached_at']);
    }
}
