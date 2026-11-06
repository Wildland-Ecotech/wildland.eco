<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkApplicationContactRequest;
use App\Models\LeafApplication;
use App\Services\ApplicationReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settling a leaf application's contact from its admin page
 * (/admin/leaves/{application}): marking its review done, and moving or
 * linking it to another contact or a new one (ApplicationReview). Each goes
 * back to the application with a message.
 */
class ApplicationContactController extends Controller
{
    /**
     * Mark the application's contact reviewed: an admin looked, and nothing
     * needs to change.
     */
    public function review(Request $request, LeafApplication $application, ApplicationReview $review): RedirectResponse
    {
        if (! $application->needsContactReview()) {
            return $this->back($application, "Application {$application->id} doesn’t need review.");
        }

        $review->markReviewed($application, $request->user());

        return $this->back($application, "Marked application {$application->id} reviewed.");
    }

    /**
     * Move or link the application to the chosen contact, or a new one.
     */
    public function contact(LinkApplicationContactRequest $request, LeafApplication $application, ApplicationReview $review): RedirectResponse
    {
        $chosen = $request->contact();
        $wasLinked = $application->contact_id !== null;

        $contact = $review->move($application, $chosen, $request->user());

        $status = match (true) {
            $chosen === null => "Made a new contact, {$contact->name}, for application {$application->id}.",
            $wasLinked => "Moved application {$application->id} to {$contact->name}.",
            default => "Linked application {$application->id} to {$contact->name}.",
        };

        return $this->back($application, $status);
    }

    /**
     * Back to the application's page with a message.
     */
    private function back(LeafApplication $application, string $status): RedirectResponse
    {
        return redirect()->route('admin.leaves.show', $application->id)->with('status', $status);
    }
}
