<?php

namespace Tests\Feature;

use App\Models\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();
    }

    public function test_policy_pages_load_with_an_effective_date(): void
    {
        foreach (['privacy' => 'Privacy Policy', 'refunds' => 'Refund Policy'] as $route => $heading) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('<title>' . $heading . ' — Wildland Ecotech</title>', false)
                ->assertSee('<h1>' . $heading . '</h1>', false)
                ->assertSee('<p class="effective">Effective ', false);
        }
    }

    public function test_refund_policy_offers_the_customer_portal_when_configured(): void
    {
        config(['services.stripe.portal_login_url' => 'https://billing.stripe.com/p/login/test_example']);

        $this->get(route('refunds'))
            ->assertOk()
            ->assertSee('link in any receipt for it, or the button below, and sign', false)
            ->assertSee('<a class="button full" href="https://billing.stripe.com/p/login/test_example">Manage Your Recurring Donation</a>', false);
    }

    public function test_refund_policy_leaves_out_the_portal_button_without_a_link(): void
    {
        config(['services.stripe.portal_login_url' => null]);

        $this->get(route('refunds'))
            ->assertOk()
            ->assertSee('link in any receipt for it and sign', false)
            ->assertDontSee('Recurring Donation</a>', false);
    }

    public function test_privacy_policy_links_to_canceling_a_recurring_donation(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('<a href="' . route('refunds') . '#canceling">refund policy</a>', false);

        $this->get(route('refunds'))
            ->assertOk()
            ->assertSee('<h2 id="canceling">Canceling a Recurring Donation</h2>', false);
    }

    public function test_page_footers_link_to_both_policies(): void
    {
        foreach (['/', '/leaves', '/leaves/apply', '/faq', '/donate', '/privacy', '/refunds'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSeeInOrder(['<p class="legal">', 'EIN: 33-3738514<br>', $this->policyLinks()], false);
        }
    }

    public function test_donate_and_thank_you_pages_link_to_both_policies_under_the_tax_note(): void
    {
        $checkout = Checkout::factory()->oneTime()->create(['amount_cents' => 2500]);

        foreach ([route('donate'), route('donate.thank-you', $checkout)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSeeInOrder([
                    '<p class="tax-note">',
                    'Donations are tax-deductible to the extent allowed',
                    'by law. EIN: 33-3738514<br>',
                    $this->policyLinks(),
                ], false)
                ->assertDontSee('All donations are tax-deductible')
                ->assertDontSee('Your tax-deductible donation');
        }
    }

    public function test_leaf_pages_link_to_the_privacy_policy(): void
    {
        $this->get(route('leaves'))
            ->assertOk()
            ->assertSee('We never sell them, and we share them only with the services that run', false)
            ->assertSee('<p>Our <a href="' . route('privacy') . '">privacy policy</a> has the details.</p>', false);

        $this->get(route('leaves.apply'))
            ->assertOk()
            ->assertSeeInOrder([
                'By applying, you agree that:',
                "<li>You're 18 or older.</li>",
                '<a href="' . route('privacy') . '#what-we-share">privacy policy</a>',
            ], false)
            ->assertDontSee('privacy section');
    }

    public function test_faq_answers_whether_refunds_are_possible(): void
    {
        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('<details class="faq-item" id="refunds">', false)
            ->assertSee('<summary>Can I get a refund?</summary>', false)
            ->assertSee('<a href="' . route('refunds') . '">refund policy</a>', false);
    }

    private function policyLinks(): string
    {
        return '<a href="' . route('privacy') . '">Privacy Policy</a> · <a href="' . route('refunds') . '">Refund Policy</a>';
    }
}
