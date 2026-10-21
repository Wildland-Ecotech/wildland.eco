<?php

namespace Tests\Feature;

use App\Http\Controllers\LeafApplicationController;
use App\Models\LeafApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeafApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        // Evening in Fort Collins, when the UTC date is already the next day
        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));
    }

    /**
     * A valid application, with any fields overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides = []): array
    {
        return array_merge([
            'type' => LeafApplication::TYPE_DROP_OFF,
            'name' => 'Test Donor',
            'email' => 'Donor@Example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => '12',
            'ready_now' => '1',
            'referral_source' => 'A flyer',
            'questions' => '',
            'terms' => '1',
            'website' => '',
        ], $overrides);
    }

    public function test_apply_page_shows_the_form(): void
    {
        $this->get(route('leaves.apply'))
            ->assertOk()
            ->assertSee('action="' . route('leaves.apply.store') . '"', false)
            ->assertSee('id="type-drop-off" name="type" value="drop_off" checked', false)
            ->assertSee('name="website"', false)
            ->assertSee('min="2026-10-22"', false);
    }

    public function test_application_with_bags_ready_now_is_saved(): void
    {
        $this->post(route('leaves.apply.store'), $this->application(['estimated_bag_count' => ' 1,200 ']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('leaves.apply'));

        $application = LeafApplication::sole();

        $this->assertSame(LeafApplication::TYPE_DROP_OFF, $application->type);
        $this->assertSame('donor@example.com', $application->email);
        $this->assertSame(1200, $application->estimated_bag_count);
        $this->assertTrue($application->ready_now);
        $this->assertNull($application->ready_date);
        $this->assertNull($application->questions);
        $this->assertNotNull($application->terms_accepted_at);
    }

    public function test_thank_you_message_matches_the_application_type(): void
    {
        $this->followingRedirects()
            ->post(route('leaves.apply.store'), $this->application(['type' => LeafApplication::TYPE_PICKUP]))
            ->assertOk()
            ->assertSee('Thank you!')
            ->assertSee('schedule your pickup')
            ->assertDontSee('name="website"', false);
    }

    public function test_application_for_bags_ready_later_saves_the_ready_date(): void
    {
        $this->post(route('leaves.apply.store'), $this->application([
            'type' => LeafApplication::TYPE_PICKUP,
            'ready_now' => '0',
            'ready_date' => '2026-10-22',
        ]))->assertSessionHasNoErrors();

        $application = LeafApplication::sole();

        $this->assertFalse($application->ready_now);
        $this->assertSame('2026-10-22', $application->ready_date->toDateString());
    }

    public function test_ready_date_is_ignored_when_bags_are_ready_now(): void
    {
        $this->post(route('leaves.apply.store'), $this->application([
            'ready_now' => '1',
            'ready_date' => '2020-01-01',
        ]))->assertSessionHasNoErrors();

        $this->assertNull(LeafApplication::sole()->ready_date);
    }

    public function test_ready_date_is_required_when_bags_are_not_ready(): void
    {
        $this->post(route('leaves.apply.store'), $this->application(['ready_now' => '0']))
            ->assertSessionHasErrors('ready_date');

        $this->assertSame(0, LeafApplication::count());
    }

    public function test_ready_date_must_be_after_today_in_fort_collins(): void
    {
        // Already tomorrow in UTC, but still today in Fort Collins
        $this->post(route('leaves.apply.store'), $this->application([
            'ready_now' => '0',
            'ready_date' => '2026-10-21',
        ]))->assertSessionHasErrors('ready_date');
    }

    public function test_required_fields_and_terms_are_validated(): void
    {
        $this->post(route('leaves.apply.store'), $this->application([
            'type' => 'delivery',
            'name' => '',
            'email' => 'not-an-email',
            'phone' => 'call me',
            'address' => '',
            'estimated_bag_count' => 'about twenty',
            'terms' => null,
        ]))->assertSessionHasErrors([
            'type',
            'name',
            'email',
            'phone',
            'address',
            'estimated_bag_count',
            'terms',
        ]);

        $this->assertSame(0, LeafApplication::count());
    }

    public function test_errors_are_shown_and_input_is_kept(): void
    {
        $this->from(route('leaves.apply'))
            ->followingRedirects()
            ->post(route('leaves.apply.store'), $this->application([
                'type' => LeafApplication::TYPE_PICKUP,
                'terms' => null,
            ]))
            ->assertOk()
            ->assertSee('Please agree to the terms to apply.')
            ->assertSee('value="Test Donor"', false)
            ->assertSee('value="(970) 555-0123"', false)
            ->assertSee('id="type-pickup" name="type" value="pickup" checked', false)
            ->assertSee('id="ready-now" name="ready_now" value="1" checked', false);
    }

    public function test_filled_spam_trap_is_rejected(): void
    {
        $this->post(route('leaves.apply.store'), $this->application(['website' => 'https://spam.example']))
            ->assertSessionHasErrors('website');

        $this->assertSame(0, LeafApplication::count());
    }

    public function test_saved_applications_are_limited_per_hour(): void
    {
        for ($i = 0; $i < LeafApplicationController::MAX_PER_HOUR; $i++) {
            $this->post(route('leaves.apply.store'), $this->application())->assertSessionHasNoErrors();
        }

        $this->post(route('leaves.apply.store'), $this->application())
            ->assertSessionHasErrors('form');

        $this->assertSame(LeafApplicationController::MAX_PER_HOUR, LeafApplication::count());

        $this->travel(61)->minutes();

        $this->post(route('leaves.apply.store'), $this->application())->assertSessionHasNoErrors();
    }

    public function test_validation_errors_do_not_count_toward_the_limit(): void
    {
        for ($i = 0; $i < LeafApplicationController::MAX_PER_HOUR + 1; $i++) {
            $this->post(route('leaves.apply.store'), $this->application(['terms' => null]));
        }

        $this->post(route('leaves.apply.store'), $this->application())->assertSessionHasNoErrors();
    }
}
