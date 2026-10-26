<?php

namespace App\Http\Controllers;

use App\Services\ProofOfWork;
use Illuminate\Http\JsonResponse;

/**
 * Fresh tokens for a form that has been open a while (GET /form-tokens):
 * a CSRF token and a new proof-of-work challenge. resources/js/proof-of-work.js
 * fetches them before submitting a form whose page was loaded more than 30
 * minutes ago, since the session behind the CSRF token expires after two
 * hours (SESSION_LIFETIME) and so does a challenge (ProofOfWork::LIFETIME).
 * Without this, a visitor returning to an old tab would get a "page expired"
 * error and lose what they typed.
 *
 * Browsers only let the site's own pages read the response, so other sites
 * can't use it to get a token.
 */
class FormTokenController extends Controller
{
    public function __invoke(ProofOfWork $proofOfWork): JsonResponse
    {
        return response()
            ->json([
                // Starts a new session if the old one expired
                'csrf' => csrf_token(),
                'challenge' => $proofOfWork->issue(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
