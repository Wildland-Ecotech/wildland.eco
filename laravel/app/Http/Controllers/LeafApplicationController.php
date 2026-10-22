<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeafApplicationRequest;
use App\Models\LeafApplication;
use App\Services\ProofOfWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Applications to donate leaves to the leaf program (/leaves/apply).
 */
class LeafApplicationController extends Controller
{
    /**
     * Applications saved per visitor (by IP address) per hour. Only saved
     * applications count, so fixing validation errors never locks anyone out.
     */
    public const MAX_PER_HOUR = 5;

    /**
     * Show the application form, or the thank-you message after applying.
     */
    public function create(ProofOfWork $proofOfWork): View
    {
        return view('pages.leaves-apply', [
            // The earliest "ready by" date the form accepts
            'earliestReadyDate' => StoreLeafApplicationRequest::today()->addDay()->toDateString(),
            'proofOfWorkChallenge' => $proofOfWork->issue(),
        ]);
    }

    /**
     * Save an application and show the thank-you message.
     */
    public function store(StoreLeafApplicationRequest $request): RedirectResponse
    {
        $limiterKey = 'leaf-applications:' . $request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_PER_HOUR)) {
            return back()
                ->withInput()
                ->withErrors(['form' => 'You have sent several applications in the last hour. Please try again later, or email us at contact@wildland.eco.']);
        }

        $readyNow = $request->boolean('ready_now');

        LeafApplication::create([
            ...$request->safe()->only([
                'type',
                'name',
                'email',
                'phone',
                'address',
                'estimated_bag_count',
                'referral_source',
                'questions',
            ]),
            'ready_now' => $readyNow,
            'ready_date' => $readyNow ? null : $request->validated('ready_date'),
            'terms_accepted_at' => now(),
        ]);

        RateLimiter::hit($limiterKey, 3600);

        return redirect()
            ->route('leaves.apply')
            ->with('applied', $request->validated('type'));
    }
}
