<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();
    }

    public function test_links_use_https_when_the_proxy_reports_https(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/menu')
            ->assertOk()
            ->assertSee('href="https://', false)
            ->assertDontSee('href="http://', false);
    }
}
