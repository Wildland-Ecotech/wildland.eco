<?php

namespace Tests\Feature;

use App\Http\Controllers\DonationController;
use App\Http\Requests\StartDonationRequest;
use App\Models\Checkout;
use App\Models\FormSubmission;
use App\Models\User;
use App\Payments\HostedCheckout;
use App\Payments\PaymentGateway;
use App\Services\ProofOfWork;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The donate page, starting a checkout, and the thank-you page, with the
 * payment gateway replaced by a fake that records what it was asked to do.
 */
class DonateFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake payment gateway, bound in place of Stripe.
     */
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->gateway = new class implements PaymentGateway
        {
            public bool $available = true;

            public ?Exception $failure = null;

            /** @var array<int, array{checkout: Checkout, successUrl: string, cancelUrl: string}> */
            public array $started = [];

            public function name(): string
            {
                return 'fake';
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function startCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): HostedCheckout
            {
                if ($this->failure) {
                    throw $this->failure;
                }

                $this->started[] = compact('checkout', 'successUrl', 'cancelUrl');
                $id = 'cs_fake_' . count($this->started);

                return new HostedCheckout($id, "https://pay.example/{$id}", CarbonImmutable::now()->addDay());
            }
        };

        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /**
     * Find a nonce for a challenge, as resources/js/proof-of-work.js does.
     */
    private function solve(string $challenge): string
    {
        $prefix = explode('.', $challenge)[2];

        for ($nonce = 0; ! str_starts_with(hash('sha256', "{$challenge}:{$nonce}"), $prefix); $nonce++);

        return (string) $nonce;
    }

    /**
     * A valid monthly $20 donation with a solved proof of work, with any fields
     * overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function donation(array $overrides = []): array
    {
        $challenge = app(ProofOfWork::class)->issue();

        return array_merge([
            'frequency' => Checkout::FREQUENCY_MONTHLY,
            'amount_monthly' => '20',
            'custom_amount' => '',
            'name' => 'Jamie Rivera',
            'email' => ' Jamie@Example.com ',
            'pow_challenge' => $challenge,
            'pow_nonce' => $this->solve($challenge),
        ], $overrides);
    }

    private function donate(array $overrides = [])
    {
        return $this->from(route('donate'))->post(route('donate.store'), $this->donation($overrides));
    }

    public function test_donate_page_shows_the_form(): void
    {
        $this->get(route('donate'))
            ->assertOk()
            ->assertSee('action="' . route('donate.store') . '"', false)
            ->assertSee('data-proof-of-work', false)
            ->assertSee('name="pow_challenge"', false)
            ->assertDontSee('name="website"', false)
            ->assertSee('<noscript>', false)
            ->assertSee('id="name" name="name"', false)
            ->assertSee('id="email" name="email"', false)
            ->assertSee('>Donate</button>', false)
            ->assertDontSee('data-proof-of-work-button disabled', false)
            ->assertDontSee(DonationController::UNAVAILABLE_MESSAGE);
    }

    public function test_no_frequency_is_chosen_until_the_visitor_picks_one(): void
    {
        $content = $this->get(route('donate'))->assertOk()->getContent();

        // The amounts, fields, and button are inside .donate-details, which
        // CSS shows only once a frequency radio is checked
        $this->assertDoesNotMatchRegularExpression('/data-frequency data-suffix="[^"]*" checked/', $content);
        $this->assertLessThan(strpos($content, 'id="custom-amount"'), strpos($content, 'class="donate-details"'));
        $this->assertLessThan(strpos($content, 'data-donate-button'), strpos($content, 'class="donate-details"'));
    }

    public function test_donate_page_says_when_online_giving_is_unavailable(): void
    {
        $this->gateway->available = false;

        $this->get(route('donate'))
            ->assertOk()
            ->assertSee(DonationController::UNAVAILABLE_MESSAGE)
            ->assertSee('data-proof-of-work-button disabled', false)
            ->assertSee('data-unavailable', false)
            ->assertDontSee('<noscript>', false);
    }

    public function test_a_donation_creates_the_donor_and_checkout_and_sends_them_to_pay(): void
    {
        $response = $this->donate();

        $user = User::sole();
        $checkout = Checkout::sole();

        $response->assertRedirect('https://pay.example/cs_fake_1');

        $this->assertSame('jamie@example.com', $user->email);
        $this->assertSame('Jamie Rivera', $user->name);
        $this->assertNull($user->password);

        $this->assertTrue($checkout->user->is($user));
        $this->assertSame(2000, $checkout->amount_cents);
        $this->assertSame('usd', $checkout->currency);
        $this->assertSame(Checkout::FREQUENCY_MONTHLY, $checkout->frequency);
        $this->assertSame(Checkout::STATUS_STARTED, $checkout->status);
        $this->assertSame('fake', $checkout->gateway);
        $this->assertSame('cs_fake_1', $checkout->gateway_session_id);
        $this->assertNotNull($checkout->expires_at);

        [$started] = $this->gateway->started;

        $this->assertTrue($started['checkout']->is($checkout));
        $this->assertSame(route('donate.thank-you', $checkout), $started['successUrl']);
        $this->assertStringEndsWith("/donate/thank-you/{$checkout->reference}", $started['successUrl']);
        $this->assertSame(route('donate'), $started['cancelUrl']);
    }

    public function test_each_frequency_uses_its_own_suggested_amounts(): void
    {
        $this->donate(['frequency' => Checkout::FREQUENCY_ONE_TIME, 'amount_one_time' => '50', 'amount_monthly' => '100'])
            ->assertSessionHasNoErrors();

        $this->donate(['frequency' => Checkout::FREQUENCY_ANNUAL, 'amount_annual' => '5000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [[Checkout::FREQUENCY_ONE_TIME, 5000], [Checkout::FREQUENCY_ANNUAL, 500000]],
            Checkout::orderBy('id')->get()->map(fn ($checkout) => [$checkout->frequency, $checkout->amount_cents])->all(),
        );
    }

    public function test_a_suggested_amount_for_another_frequency_is_ignored(): void
    {
        $this->donate(['frequency' => Checkout::FREQUENCY_ANNUAL, 'amount_monthly' => '50'])
            ->assertSessionHasErrors(['amount' => 'Choose an amount, or enter your own.']);

        $this->assertSame(0, Checkout::count());
    }

    public function test_a_custom_amount_is_cleaned_and_used_instead_of_a_suggested_amount(): void
    {
        $this->donate(['custom_amount' => ' $1,250.5 ', 'amount_monthly' => '20'])->assertSessionHasNoErrors();

        $this->assertSame(125050, Checkout::sole()->amount_cents);
    }

    public function test_amounts_must_be_from_one_to_9999_dollars_with_at_most_two_decimal_places(): void
    {
        foreach (['0.99', '10000', '9999.01', '12.345', 'abc', '-5'] as $amount) {
            $this->donate(['custom_amount' => $amount])->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, Checkout::count());

        foreach (['1', '9999', '9,999.00', '0019.99'] as $amount) {
            $this->donate(['custom_amount' => $amount])->assertSessionHasNoErrors();
        }

        $this->assertSame([100, 999900, 999900, 1999], Checkout::orderBy('id')->pluck('amount_cents')->all());
    }

    public function test_frequency_name_and_email_are_required_and_input_is_kept(): void
    {
        $this->donate(['frequency' => 'weekly', 'name' => '', 'email' => 'not-an-email'])
            ->assertRedirect(route('donate'))
            ->assertSessionHasErrors(['frequency', 'name', 'email']);

        $this->followingRedirects()
            ->from(route('donate'))
            ->post(route('donate.store'), $this->donation(['name' => 'Jamie Rivera', 'email' => 'not-an-email', 'custom_amount' => '42']))
            ->assertOk()
            ->assertSee('Enter a valid email address')
            ->assertSee('value="Jamie Rivera"', false)
            ->assertSee('value="42"', false)
            ->assertSee('id="frequency-monthly" value="monthly" data-frequency data-suffix="/month" checked', false)
            ->assertSee('id="email" name="email" value="not-an-email"', false);

        $this->assertSame(0, User::count());
    }

    public function test_an_existing_donors_name_is_never_changed_by_the_form(): void
    {
        $donor = User::factory()->create(['name' => 'Original Name', 'email' => 'jamie@example.com']);

        $this->donate(['name' => 'Someone Else'])->assertSessionHasNoErrors();

        $this->assertSame('Original Name', $donor->fresh()->name);
        $this->assertSame(1, User::count());
        $this->assertTrue(Checkout::sole()->user->is($donor));
    }

    public function test_a_donor_without_a_name_gets_the_name_they_enter(): void
    {
        // Such as a donor imported from a Payment Link without a name
        $donor = User::factory()->create(['name' => null, 'email' => 'jamie@example.com']);

        $this->donate()->assertSessionHasNoErrors();

        $this->assertSame('Jamie Rivera', $donor->fresh()->name);
    }

    public function test_a_soft_deleted_donor_is_reused_without_being_restored(): void
    {
        $donor = User::factory()->create(['email' => 'jamie@example.com']);
        $donor->delete();

        $this->donate()->assertSessionHasNoErrors();

        $this->assertSoftDeleted($donor);
        $this->assertSame($donor->id, Checkout::sole()->user_id);
    }

    public function test_a_field_filled_in_by_autofill_never_blocks_a_donation(): void
    {
        // Browsers and password managers fill in fields such as "website",
        // which the form once used as a spam trap
        $this->donate(['website' => 'https://example.com'])->assertSessionHasNoErrors();

        $this->assertSame(1, Checkout::count());
    }

    public function test_every_rule_has_its_own_message(): void
    {
        // A rule without a specific message falls back to the field's own
        // message, never Laravel's default wording
        $this->donate(['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['name' => 'Enter a name of 255 characters or fewer.']);
        $this->donate(['name' => ['not', 'text']])
            ->assertSessionHasErrors(['name' => 'Enter your name as text.']);
        $this->donate(['pow_nonce' => str_repeat('1', 17)])
            ->assertSessionHasErrors(['pow_nonce' => StartDonationRequest::PROOF_OF_WORK_MESSAGE]);
    }

    public function test_missing_proof_of_work_is_rejected(): void
    {
        $this->donate(['pow_nonce' => ''])
            ->assertSessionHasErrors(['pow_nonce' => StartDonationRequest::PROOF_OF_WORK_MESSAGE]);

        $this->assertSame(0, Checkout::count());
        $this->assertEmpty($this->gateway->started);
    }

    public function test_started_checkouts_are_limited_per_hour(): void
    {
        for ($i = 0; $i < DonationController::MAX_PER_HOUR; $i++) {
            $this->donate()->assertSessionHasNoErrors();
        }

        $this->donate()->assertSessionHasErrors('form');

        $this->assertSame(DonationController::MAX_PER_HOUR, Checkout::count());

        $this->travel(61)->minutes();

        $this->donate()->assertSessionHasNoErrors();
    }

    public function test_a_gateway_failure_is_reported_and_the_donor_can_try_again(): void
    {
        Exceptions::fake();
        $this->gateway->failure = new RuntimeException('Stripe is unreachable');

        $this->donate()
            ->assertRedirect(route('donate'))
            ->assertSessionHasErrors('form')
            ->assertSessionHasInput('name', 'Jamie Rivera');

        Exceptions::assertReported(RuntimeException::class);

        // The attempt stays recorded, without a session
        $this->assertNull(Checkout::sole()->gateway_session_id);
        $this->assertSame(Checkout::STATUS_STARTED, Checkout::sole()->status);

        // Failures don't count toward the hourly limit
        $this->gateway->failure = null;

        for ($i = 0; $i < DonationController::MAX_PER_HOUR; $i++) {
            $this->donate()->assertSessionHasNoErrors();
        }
    }

    public function test_nothing_is_recorded_while_online_giving_is_unavailable(): void
    {
        $this->gateway->available = false;

        $this->donate()->assertSessionHasErrors(['form' => DonationController::UNAVAILABLE_MESSAGE]);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Checkout::count());
    }

    public function test_thank_you_page_shows_the_donation_but_not_the_donor(): void
    {
        config(['services.stripe.portal_login_url' => 'https://billing.stripe.com/p/login/test_example']);

        $donor = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
        $checkout = Checkout::factory()->for($donor)->create(['amount_cents' => 3750]);

        $response = $this->get(route('donate.thank-you', $checkout))
            ->assertOk()
            ->assertSee('Thank You')
            ->assertSee('monthly donation of $37.50')
            ->assertSee('Manage Your Recurring Donation')
            ->assertDontSee('gift')
            ->assertSee('href="https://billing.stripe.com/p/login/test_example"', false)
            ->assertDontSee('Jamie')
            ->assertDontSee('jamie@example.com');

        // The page itself doesn't ask for another donation; the menu's
        // Donate link is on every page
        $this->assertStringNotContainsString('href="' . route('donate') . '"', $this->withoutMenuOverlay($response->getContent()));
    }

    public function test_thank_you_page_for_a_one_time_donation_has_no_recurring_donation_link(): void
    {
        config(['services.stripe.portal_login_url' => 'https://billing.stripe.com/p/login/test_example']);

        $checkout = Checkout::factory()->oneTime()->create(['amount_cents' => 2500]);

        $this->get(route('donate.thank-you', $checkout))
            ->assertOk()
            ->assertSee('Your donation of $25 protects')
            ->assertDontSee('billing.stripe.com');
    }

    public function test_thank_you_page_needs_a_real_checkout_reference(): void
    {
        $this->get('/donate/thank-you/not-a-reference')->assertNotFound();
        $this->get('/donate/thank-you/' . Str::uuid())->assertNotFound();
        $this->get('/donate/thank-you/' . Checkout::factory()->create()->id)->assertNotFound();
    }

    public function test_every_donation_submission_is_recorded_as_it_arrives(): void
    {
        $this->donate()->assertRedirect('https://pay.example/cs_fake_1');

        $submission = FormSubmission::sole();

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{6}$/', $submission->reference);
        $this->assertSame(FormSubmission::FORM_DONATION, $submission->form);
        $this->assertSame(FormSubmission::OUTCOME_SENT_TO_PAYMENT, $submission->outcome);
        $this->assertSame(Checkout::sole()->id, $submission->checkout_id);
        $this->assertSame('Jamie Rivera', $submission->name);
        $this->assertSame('jamie@example.com', $submission->email);
        $this->assertSame(Checkout::FREQUENCY_MONTHLY, $submission->data['frequency']);
        $this->assertSame('20', $submission->data['amount_monthly']);
        $this->assertNull($submission->errors);

        // Security tokens are never stored
        $this->assertArrayNotHasKey('_token', $submission->data);
        $this->assertArrayNotHasKey('pow_challenge', $submission->data);
        $this->assertArrayNotHasKey('pow_nonce', $submission->data);
    }

    public function test_a_rejected_donation_keeps_what_was_typed_and_shows_its_reference(): void
    {
        // Follows the redirect within the same session, as a browser does
        $response = $this->followingRedirects()->donate(['name' => '', 'email' => 'jamie at example']);

        $submission = FormSubmission::sole();

        $this->assertSame(FormSubmission::OUTCOME_REJECTED, $submission->outcome);
        $this->assertSame('jamie at example', $submission->email);
        $this->assertNull($submission->name);
        $this->assertSame(['Enter your name.'], $submission->errors['name']);
        $this->assertSame(0, Checkout::count());

        $response
            ->assertOk()
            ->assertSee('Enter your name.')
            ->assertSee("Reference: <strong>{$submission->reference}</strong>", false)
            ->assertSee('include it so we can find your submission');
    }

    public function test_a_payment_processor_failure_is_recorded_with_its_checkout(): void
    {
        Exceptions::fake();
        $this->gateway->failure = new RuntimeException('Stripe is unreachable');

        $this->donate()->assertSessionHasErrors('form');

        $submission = FormSubmission::sole();

        $this->assertSame(FormSubmission::OUTCOME_PAYMENT_ERROR, $submission->outcome);
        $this->assertSame(Checkout::sole()->id, $submission->checkout_id);
        $this->assertStringContainsString('could not reach our payment processor', $submission->errors['form'][0]);
    }

    public function test_unavailable_and_rate_limited_donations_are_recorded(): void
    {
        $this->gateway->available = false;
        $this->donate();

        $this->assertSame(FormSubmission::OUTCOME_UNAVAILABLE, FormSubmission::latest('id')->first()->outcome);

        $this->gateway->available = true;

        for ($i = 0; $i <= DonationController::MAX_PER_HOUR; $i++) {
            $this->donate();
        }

        $this->assertSame(FormSubmission::OUTCOME_RATE_LIMITED, FormSubmission::latest('id')->first()->outcome);
        $this->assertSame(DonationController::MAX_PER_HOUR, FormSubmission::where('outcome', FormSubmission::OUTCOME_SENT_TO_PAYMENT)->count());
    }
}
