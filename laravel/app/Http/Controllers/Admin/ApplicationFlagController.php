<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlagLeafApplicationRequest;
use App\Models\LeafApplication;
use Illuminate\Http\RedirectResponse;

/**
 * Marking leaf applications as a test or spam from their admin page
 * (/admin/leaves/{application}), and unmarking applications marked by
 * mistake (LeafApplication::flag() and unflag()). Each goes back to the
 * application with a message.
 */
class ApplicationFlagController extends Controller
{
    /**
     * The choices on the application's page, keyed by LeafApplication::FLAGS.
     */
    public const FLAG_CHOICES = [
        LeafApplication::FLAG_SPAM => 'Spam',
        LeafApplication::FLAG_TEST => 'Test',
    ];

    /**
     * Mark the application as a test or spam, with an optional note.
     */
    public function flag(FlagLeafApplicationRequest $request, LeafApplication $application): RedirectResponse
    {
        $flag = $request->validated('flag');

        $application->flag($request->user(), $flag, $request->validated('note'));

        $as = $flag === LeafApplication::FLAG_TEST ? 'a test' : 'spam';

        return $this->back($application, "Marked application {$application->id} as {$as}.");
    }

    /**
     * Unmark an application marked by mistake.
     */
    public function unflag(LeafApplication $application): RedirectResponse
    {
        if (! $application->isFlagged()) {
            return $this->back($application, "Application {$application->id} isn’t marked as a test or spam.");
        }

        $application->unflag();

        return $this->back($application, "Unmarked application {$application->id}.");
    }

    /**
     * Back to the application's page with a message.
     */
    private function back(LeafApplication $application, string $status): RedirectResponse
    {
        return redirect()->route('admin.leaves.show', $application->id)->with('status', $status);
    }
}
