<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * The branded error pages in resources/views/errors. Each one says what went
 * wrong and what to do next, instead of Laravel's bare default pages.
 */
class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        // Production shows these pages; with debug on, Laravel shows details
        config(['app.debug' => false]);

        Route::middleware('web')->group(function () {
            Route::post('/test/expired', fn () => throw new TokenMismatchException);
            Route::get('/test/broken', fn () => throw new RuntimeException('Broken'));
            Route::get('/test/too-many', fn () => abort(429));
            Route::get('/test/forbidden', fn () => abort(403));
            Route::get('/test/unauthorized', fn () => abort(401));
            Route::get('/test/method', fn () => abort(405));
            Route::get('/test/maintenance', fn () => abort(503));
        });
    }

    public function test_a_missing_page_links_home_and_to_donate(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee("We couldn't find that page")
            ->assertSee('<a class="button full" href="' . route('home') . '">Go to the Home Page</a>', false)
            ->assertSee('<a class="button dark" href="' . route('donate') . '">Donate</a>', false)
            ->assertSee('contact@wildland.eco');
    }

    public function test_an_expired_page_says_nothing_was_charged_and_links_back_to_the_form(): void
    {
        $this->from(route('donate'))
            ->post('/test/expired')
            ->assertStatus(419)
            ->assertSee('This page expired')
            ->assertSee('nothing was charged')
            ->assertSee('<a class="button full" href="' . route('donate') . '">Back to the Form</a>', false);
    }

    public function test_a_server_error_tells_donors_to_check_for_a_receipt(): void
    {
        Exceptions::fake();

        $this->get('/test/broken')
            ->assertServerError()
            ->assertSee('Something went wrong on our end')
            ->assertSee('check your email for a receipt')
            ->assertDontSee('Broken');
    }

    public function test_other_errors_have_pages_of_their_own(): void
    {
        $this->get('/test/too-many')->assertStatus(429)->assertSee('Please wait a moment');
        $this->get('/test/maintenance')->assertStatus(503)->assertSee("We'll be right back");
        $this->get('/test/forbidden')->assertForbidden()->assertSee('Error 403')->assertSee("We couldn't complete that request");
        $this->get('/test/unauthorized')->assertUnauthorized()->assertSee('Error 401');
        $this->get('/test/method')->assertStatus(405)->assertSee('Error 405');
    }

    public function test_every_error_page_laravel_ships_is_replaced(): void
    {
        // Laravel uses its own page for a status before falling back to 4xx
        // or 5xx, so each of its pages needs one of ours
        $builtIn = glob(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/[0-9]*.blade.php'));

        $this->assertNotEmpty($builtIn);

        foreach ($builtIn as $path) {
            $this->assertFileExists(resource_path('views/errors/' . basename($path)), basename($path) . ' would show Laravel\'s default page');
        }
    }
}
