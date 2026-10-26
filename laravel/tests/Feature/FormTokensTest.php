<?php

namespace Tests\Feature;

use App\Services\ProofOfWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /form-tokens: a fresh CSRF token and proof-of-work challenge for a form
 * that has been open a while (see resources/js/proof-of-work.js).
 */
class FormTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();
    }

    public function test_it_returns_the_sessions_csrf_token_and_a_new_challenge(): void
    {
        $response = $this->getJson(route('form-tokens'))->assertOk();

        $this->assertSame(session()->token(), $response->json('csrf'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // The challenge is a real one: solving it verifies
        $challenge = $response->json('challenge');
        $prefix = explode('.', $challenge)[2];

        for ($nonce = 0; ! str_starts_with(hash('sha256', "{$challenge}:{$nonce}"), $prefix); $nonce++);

        $this->assertTrue(app(ProofOfWork::class)->verify($challenge, (string) $nonce));
    }

    public function test_each_request_gets_a_new_challenge(): void
    {
        $first = $this->getJson(route('form-tokens'))->json('challenge');
        $second = $this->getJson(route('form-tokens'))->json('challenge');

        $this->assertNotSame($first, $second);
    }

    public function test_both_forms_know_where_to_refresh_their_tokens(): void
    {
        $attribute = 'data-form-tokens-url="' . route('form-tokens') . '"';

        $this->get(route('donate'))->assertSee($attribute, false);
        $this->get(route('leaves.apply'))->assertSee($attribute, false);
    }

    public function test_requests_are_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('form-tokens'))->assertOk();
        }

        $this->getJson(route('form-tokens'))->assertTooManyRequests();
    }
}
