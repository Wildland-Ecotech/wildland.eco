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

    public function test_home_page_renders_each_section(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="mission"', false)
            ->assertSee('id="programs"', false)
            ->assertSee('id="projects"', false)
            ->assertSee('Building Abundance');
    }

    public function test_home_accordion_opens_the_first_program_without_javascript(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<details class="accordion-item" name="programs" id="leaves" open>', false)
            ->assertSee('<details class="accordion-item" name="programs" id="water">', false);
    }

    public function test_project_cards_link_to_their_modals(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="#high-park" data-modal-open', false)
            ->assertSee('id="high-park"', false)
            ->assertSee('href="#ge-moreau" data-modal-open', false)
            ->assertSee('id="ge-moreau"', false);
    }

    public function test_blob_shapes_render_once_per_page(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'id="blob-1"'));
        $this->assertSame(4, substr_count($content, 'data-blob '));
    }

    public function test_no_page_links_to_donate_page_until_payments_are_integrated(): void
    {
        foreach (['/', '/menu', '/donate', '/leaves', '/leaves/apply', '/faq'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertDontSee('href="' . route('donate') . '"', false);
        }
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

    public function test_leaves_page_leads_with_leaving_the_leaves(): void
    {
        $this->get('/leaves')
            ->assertOk()
            ->assertSeeInOrder(['Leave the Leaves', 'Donate Your Leaves', 'Leaf Guidelines', 'Privacy']);
    }

    public function test_leaves_page_offers_drop_off_before_pickup(): void
    {
        $this->get('/leaves')
            ->assertOk()
            ->assertSeeInOrder(['id="drop-off"', 'Preferred', 'id="pickup"', '1 to 2 weeks in advance'], false);
    }

    public function test_leaves_page_links_to_the_application_at_the_top_and_bottom(): void
    {
        $content = $this->get('/leaves')->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'href="' . route('leaves.apply') . '"'));
        // The first is in "Donate Your Leaves", before the drop-off and pickup cards
        $first = strpos($content, 'href="' . route('leaves.apply') . '"');
        $this->assertGreaterThan(strpos($content, 'id="donate-leaves"'), $first);
        $this->assertLessThan(strpos($content, 'id="drop-off"'), $first);
        $this->assertGreaterThan(strpos($content, 'id="privacy"'), strrpos($content, 'href="' . route('leaves.apply') . '"'));
    }

    public function test_faq_questions_open_without_javascript(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('Frequently Asked Questions')
            ->assertSee('<details class="faq-item" id="nonprofit">', false)
            ->assertSee('<summary>Where else can you learn about our work?</summary>', false);
    }

    public function test_faq_uses_the_agreed_names(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('Wildland Ecology Technologies (“Wildland Ecotech”)', false)
            ->assertSee('Our EIN is 33-3738514.')
            ->assertSee('Rachel Shelton')
            ->assertDontSee('Rachel Fish')
            ->assertDontSee('WET');
    }

    public function test_donate_page_footer_links_home_instead_of_to_donate(): void
    {
        $this->get('/donate')
            ->assertOk()
            ->assertSee('Back to Home')
            ->assertDontSee('href="' . route('donate') . '"', false);
    }
}
