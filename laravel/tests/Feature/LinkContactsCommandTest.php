<?php

namespace Tests\Feature;

use App\Console\Commands\LinkContacts;
use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Services\ContactLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan contacts:link`: linking applications made before contacts
 * existed, or whose linking failed, with a dry run first.
 */
class LinkContactsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 19:30', 'America/Denver'));
    }

    /**
     * An application made $daysAgo days ago, not linked to a contact.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function application(array $overrides = [], int $daysAgo = 1): LeafApplication
    {
        $application = LeafApplication::create([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 12,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            ...$overrides,
        ]);

        $application->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $application;
    }

    /**
     * The summary table's rows for these counts, in order: new contacts,
     * linked to an existing contact, not linked, and failed.
     *
     * @return list<array{string, int}>
     */
    private function summary(int ...$counts): array
    {
        return array_map(null, array_values(LinkContacts::OUTCOME_LABELS), $counts);
    }

    public function test_links_applications_oldest_first(): void
    {
        // The newer application was saved first, so only the order of
        // created_at decides which phone number ends up primary
        $newer = $this->application(['phone' => '970-555-0199'], daysAgo: 2);
        $older = $this->application(daysAgo: 10);

        $this->artisan('contacts:link')
            ->expectsTable(['Applications', 'Count'], $this->summary(1, 1, 0, 0))
            ->expectsOutputToContain('2 of 2 applications linked or flagged.')
            ->assertSuccessful();

        $contact = Contact::sole();
        $this->assertSame($contact->id, $older->fresh()->contact_id);
        $this->assertSame($contact->id, $newer->fresh()->contact_id);
        $this->assertSame('970-555-0199', $contact->primary(ContactDetail::TYPE_PHONE)->value);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $application = $this->application();

        $this->artisan('contacts:link', ['--dry-run' => true])
            ->expectsTable(['Would be', 'Count'], $this->summary(1, 0, 0, 0))
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertSuccessful();

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, ContactDetail::count());
        $this->assertNull($application->fresh()->contact_id);
    }

    public function test_lists_the_applications_that_need_review(): void
    {
        $this->application(daysAgo: 10);
        // Someone else at the same email and phone number
        $flagged = $this->application(['name' => 'Alex Rivera'], daysAgo: 2);

        $this->artisan('contacts:link')
            ->expectsTable(['Applications', 'Count'], $this->summary(2, 0, 0, 0))
            ->expectsOutputToContain('1 need an admin to look:')
            ->expectsTable(LinkContacts::REVIEW_HEADERS, [
                [$flagged->id, 'Nov 3, 2026 7:30 PM MST', 'Alex Rivera', 'Email also on another contact; Phone number also on another contact'],
            ])
            ->assertSuccessful();
    }

    public function test_nothing_to_do_once_every_application_is_linked(): void
    {
        $this->application();
        $this->artisan('contacts:link')->assertSuccessful();

        $this->artisan('contacts:link')
            ->expectsOutputToContain('Every application is already linked to a contact or waiting for review.')
            ->assertSuccessful();

        $this->assertSame(1, Contact::count());
    }

    public function test_deleted_applications_are_left_out(): void
    {
        $this->application()->delete();

        $this->artisan('contacts:link')
            ->expectsOutputToContain('Every application is already linked to a contact or waiting for review.')
            ->assertSuccessful();

        $this->assertSame(0, Contact::count());
    }

    public function test_a_failure_is_reported_and_fails_the_command(): void
    {
        Exceptions::fake();
        $application = $this->application();
        $this->mock(ContactLinker::class)->shouldReceive('link')->andThrow(new RuntimeException('Linking failed'));

        $this->artisan('contacts:link')
            ->expectsTable(['Applications', 'Count'], $this->summary(0, 0, 0, 1))
            ->expectsOutputToContain("Application {$application->id}: Linking failed")
            ->expectsOutputToContain('Running contacts:link again retries them.')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
    }
}
