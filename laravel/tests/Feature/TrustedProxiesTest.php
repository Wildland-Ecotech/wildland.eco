<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

    public function test_the_visitor_address_comes_from_the_proxy(): void
    {
        // Sessions and rate limits use this address. On servers, Caddy sends
        // the visitor's address alone in X-Forwarded-For
        Route::get('/test/client-ip', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/test/client-ip')
            ->assertOk()
            ->assertSeeText('203.0.113.7');
    }
}
