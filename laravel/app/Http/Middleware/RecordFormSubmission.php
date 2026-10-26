<?php

namespace App\Http\Middleware;

use App\Models\FormSubmission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records each submission of a form the moment it arrives, before
 * validation or anything else can fail, then records what happened to it.
 * Used on the donate and leaf application forms' POST routes, as
 * `RecordFormSubmission:<form>`, where <form> is a FormSubmission::FORMS value.
 *
 * It runs right after the session starts (bootstrap/app.php), before the
 * CSRF check, so a submission from a page that had expired is recorded too.
 * Controllers record their own outcomes with FormSubmission::markAs();
 * anything left unrecorded is worked out here from the response.
 */
class RecordFormSubmission
{
    /**
     * Shown with the form's errors, so the visitor can tell us which
     * submission was theirs.
     */
    public const REFERENCE_SESSION_KEY = 'submission_reference';

    public function handle(Request $request, Closure $next, string $form): Response
    {
        $submission = FormSubmission::start($form, $request);
        $request->attributes->set(FormSubmission::REQUEST_ATTRIBUTE, $submission);

        $response = $next($request);

        $this->finish($submission, $request, $response);

        return $response;
    }

    /**
     * Record the outcome and the messages shown, and for a failure, log it
     * and show its reference.
     */
    private function finish(FormSubmission $submission, Request $request, Response $response): void
    {
        $messages = $this->errorMessages($request);

        if ($submission->outcome === FormSubmission::OUTCOME_RECEIVED) {
            $submission->outcome = match (true) {
                $response->getStatusCode() === 419 => FormSubmission::OUTCOME_EXPIRED,
                $response->getStatusCode() >= 500 => FormSubmission::OUTCOME_ERROR,
                $messages !== [] => FormSubmission::OUTCOME_REJECTED,
                default => FormSubmission::OUTCOME_RECEIVED,
            };
        }

        if ($messages !== [] && $submission->errors === null) {
            $submission->errors = $messages;
        }

        $submission->save();

        if (! $submission->failed()) {
            return;
        }

        // The reference and which fields failed, never what was typed
        Log::warning('Form submission failed', [
            'reference' => $submission->reference,
            'form' => $submission->form,
            'outcome' => $submission->outcome,
            'fields' => array_keys($submission->errors ?? []),
        ]);

        if ($request->hasSession() && $response->isRedirection()) {
            $request->session()->flash(self::REFERENCE_SESSION_KEY, $submission->reference);
        }
    }

    /**
     * The validation and form messages flashed for the visitor by this
     * request, by field.
     *
     * @return array<string, array<int, string>>
     */
    private function errorMessages(Request $request): array
    {
        $errors = $request->hasSession() ? $request->session()->get('errors') : null;

        return $errors instanceof ViewErrorBag ? $errors->getBag('default')->getMessages() : [];
    }
}
