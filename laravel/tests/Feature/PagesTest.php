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
}
