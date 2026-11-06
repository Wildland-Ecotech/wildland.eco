<?php

namespace Tests\Feature;

use Tests\TestCase;

class PolicyPagesTest extends TestCase
{
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
}
