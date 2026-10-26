<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordFormSubmission;
use App\Models\FormSubmission;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * RecordFormSubmission on its own: what it records when a request fails in
 * ways the controllers never see, and the limits on what it stores. The
 * forms' own outcomes are covered in DonateFormTest and LeafApplicationTest.
 */
class RecordFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', RecordFormSubmission::class . ':' . FormSubmission::FORM_DONATION])->group(function () {
            Route::post('/test/expired', fn () => throw new TokenMismatchException);
            Route::post('/test/broken', fn () => throw new RuntimeException('Broken'));
            Route::post('/test/ok', fn () => response('OK'));
        });
    }

    public function test_it_runs_after_the_session_starts_and_before_the_csrf_check(): void
    {
        // The HTTP kernel gives the router its middleware groups and priority
        // when it's created, which in tests happens on the first request
        $this->app->make(HttpKernel::class);

        $middleware = app('router')->gatherRouteMiddleware(app('router')->getRoutes()->getByName('donate.store'));
        $names = array_map(fn ($name) => explode(':', $name)[0], $middleware);

        $record = array_search(RecordFormSubmission::class, $names, true);

        $this->assertGreaterThan(array_search(StartSession::class, $names, true), $record);
        $this->assertLessThan(array_search(PreventRequestForgery::class, $names, true), $record);
    }

    public function test_a_submission_from_an_expired_page_is_recorded(): void
    {
        $this->post('/test/expired', ['name' => 'Jamie Rivera', 'email' => 'jamie@example.com'])->assertStatus(419);

        $submission = FormSubmission::sole();

        $this->assertSame(FormSubmission::OUTCOME_EXPIRED, $submission->outcome);
        $this->assertSame('jamie@example.com', $submission->email);
    }

    public function test_a_server_error_is_recorded_and_logged_without_personal_details(): void
    {
        Exceptions::fake();
        Log::spy();

        $this->post('/test/broken', ['name' => 'Jamie Rivera', 'email' => 'jamie@example.com'])->assertServerError();

        $submission = FormSubmission::sole();

        $this->assertSame(FormSubmission::OUTCOME_ERROR, $submission->outcome);

        Log::shouldHaveReceived('warning')->with('Form submission failed', Mockery::on(fn ($context) => $context === [
            'reference' => $submission->reference,
            'form' => FormSubmission::FORM_DONATION,
            'outcome' => FormSubmission::OUTCOME_ERROR,
            'fields' => [],
        ]));
    }

    public function test_a_request_that_ends_without_an_outcome_stays_received(): void
    {
        $this->post('/test/ok', ['name' => 'Jamie Rivera'])->assertOk();

        $this->assertSame(FormSubmission::OUTCOME_RECEIVED, FormSubmission::sole()->outcome);
        $this->assertNull(session(RecordFormSubmission::REFERENCE_SESSION_KEY));
    }

    public function test_what_is_stored_is_limited_since_anyone_can_submit_anything(): void
    {
        $fields = collect(range(1, 40))->mapWithKeys(fn ($i) => ["field_{$i}" => "value {$i}"])->all();

        $this->post('/test/ok', [
            'name' => ['not', 'text'],
            'email' => str_repeat('a', 300),
            'questions' => str_repeat('b', 6000),
            ...$fields,
        ]);

        $submission = FormSubmission::sole();

        $this->assertSame('["not","text"]', $submission->name);
        $this->assertSame(255, mb_strlen($submission->email));
        $this->assertSame(5000, mb_strlen($submission->data['questions']));
        $this->assertCount(30, $submission->data);
    }

    public function test_references_are_unique(): void
    {
        foreach (range(1, 20) as $i) {
            $this->post('/test/ok');
        }

        $this->assertSame(20, FormSubmission::distinct('reference')->count('reference'));
    }
}
