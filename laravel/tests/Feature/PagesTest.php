<?php

namespace Tests\Feature;

use Tests\TestCase;

class PagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();
    }

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Wildland Ecotech');
    }

    public function test_menu_page_links_to_site_sections(): void
    {
        $this->get('/menu')
            ->assertOk()
            ->assertSee('Our Programs')
            ->assertSee('href="/#programs"', false)
            ->assertSee('href="/#projects"', false)
            ->assertSee('href="mailto:contact@wildland.eco"', false);
    }

    public function test_menu_button_links_to_menu_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('class="menu-button"', false)
            ->assertSee('href="' . route('menu') . '"', false);
    }

    public function test_menu_overlay_is_hidden_until_opened(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-menu-overlay hidden', false)
            ->assertSee('data-menu-toggle', false);
    }

    public function test_donate_page_renders_tiers_for_each_frequency(): void
    {
        $this->get('/donate')
            ->assertOk()
            ->assertSee('Support Our Mission')
            ->assertSee('id="frequency-monthly" value="monthly" data-frequency data-suffix="/month" checked', false)
            ->assertSee('id="tier-one-time-5"', false)
            ->assertSee('id="tier-monthly-100"', false)
            ->assertSee('id="tier-annual-5000"', false)
            ->assertSee('$5,000');
    }

    public function test_donate_button_is_disabled_until_payments_are_integrated(): void
    {
        $this->get('/donate')
            ->assertOk()
            ->assertSee('data-donate-button disabled', false);
    }

    public function test_donate_page_footer_links_home_instead_of_to_donate(): void
    {
        $this->get('/donate')
            ->assertOk()
            ->assertSee('Back to Home')
            ->assertDontSee('href="' . route('donate') . '"', false);
    }
}
