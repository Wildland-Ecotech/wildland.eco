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

    public function test_page_footers_link_to_the_donate_page(): void
    {
        foreach (['/', '/leaves', '/leaves/apply', '/faq'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('<a class="button dark" href="' . route('donate') . '">Donate</a>', false);
        }
    }

    public function test_page_footers_link_to_our_social_media(): void
    {
        foreach (['/', '/leaves', '/leaves/apply', '/faq', '/donate'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSeeInOrder(['Follow our progress', '>Instagram</a>', '>LinkedIn</a>', '>Facebook</a>'], false)
                ->assertSee('<a href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/" target="_blank" rel="noopener noreferrer">Facebook</a>', false);
        }
    }

    public function test_menu_page_links_to_the_main_pages(): void
    {
        $content = $this->get('/menu')->assertOk()->getContent();

        // The page's own links, after the menu overlay every page includes
        $page = substr($content, strpos($content, 'class="menu-page"'));

        $this->assertMatchesRegularExpression('/Home.*Leaf Collection.*FAQ.*Volunteer.*Contact Us.*Donate/s', $page);
        $this->assertStringContainsString('<a class="button" href="mailto:volunteer@wildland.eco">Volunteer</a>', $page);
        $this->assertStringContainsString('<a class="button" href="mailto:contact@wildland.eco">Contact Us</a>', $page);
        $this->assertStringContainsString('<a class="button full" href="' . route('donate') . '">Donate</a>', $page);
        $this->assertStringContainsString('class="social-links"', $page);
    }

    public function test_menu_overlay_links_to_the_main_pages_with_donate_highlighted(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['data-menu-close>Home', 'data-menu-close>Leaf Collection', 'data-menu-close>FAQ', 'data-menu-close>Volunteer', 'data-menu-close>Contact Us', 'data-menu-close>Donate'], false)
            ->assertSee('<a class="button" href="' . route('home') . '" data-menu-close>Home</a>', false)
            ->assertSee('<a class="button" href="mailto:volunteer@wildland.eco" data-menu-close>Volunteer</a>', false)
            ->assertSee('<a class="button full" href="' . route('donate') . '" data-menu-close>Donate</a>', false)
            ->assertDontSee('data-menu-close>Our Programs', false)
            ->assertDontSee('data-menu-close>Our Projects', false);
    }

    public function test_the_menu_links_to_our_social_media(): void
    {
        foreach (['/', '/menu'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer" aria-label="Instagram"', false)
                ->assertSee('href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"', false)
                ->assertSee('href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/" target="_blank" rel="noopener noreferrer" aria-label="Facebook"', false);
        }
    }

    public function test_home_banner_asks_visitors_to_donate_or_volunteer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<div class="donate-cta" data-rainbow-border>', false)
            ->assertSee('Get involved and help us fight back against wildfires')
            ->assertSeeInOrder([
                '<div class="cta-links">',
                '<a href="' . route('donate') . '">Donate</a>',
                '<span class="cta-divider" aria-hidden="true">|</span>',
                '<a href="mailto:volunteer@wildland.eco">Volunteer</a>',
            ], false)
            ->assertDontSee('Follow our socials');
    }

    public function test_project_popups_link_to_facebook(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, '<a class="button dark" href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/"'));
    }

    public function test_home_collecting_leaves_section_links_to_the_leaf_program(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        $section = substr($content, strpos($content, 'id="leaves"'), strpos($content, 'id="water"') - strpos($content, 'id="leaves"'));

        $this->assertStringContainsString('<a class="accordion-link" href="' . route('leaves') . '">Learn more about leaf collection', $section);
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
            ->assertSee('id="frequency-monthly" value="monthly" data-frequency data-suffix="/month" >', false)
            ->assertSee('id="tier-one-time-5"', false)
            ->assertSee('id="tier-monthly-100"', false)
            ->assertSee('id="tier-annual-5000"', false)
            ->assertSee('$5,000');
    }

    public function test_donate_button_is_disabled_until_stripe_is_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $this->get('/donate')
            ->assertOk()
            ->assertSee('Online donations are not available right now.')
            ->assertSee('data-proof-of-work-button disabled', false);
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
        $response = $this->get('/donate')
            ->assertOk()
            ->assertSee('Back to Home');

        // The menu's Donate link is on every page, including this one
        $this->assertStringNotContainsString('href="' . route('donate') . '"', $this->withoutMenuOverlay($response->getContent()));
    }
}
