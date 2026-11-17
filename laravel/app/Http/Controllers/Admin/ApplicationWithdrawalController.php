<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawLeafApplicationRequest;
use App\Models\LeafApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Withdrawing pending leaf applications from their admin page
 * (/admin/leaves/{application}), with why and a note, and reopening
 * withdrawn applications (LeafApplication::withdraw() and reopen()). Each
 * goes back to the application with a message.
 */
class ApplicationWithdrawalController extends Controller
{
    /**
     * Withdraw a pending application.
     */
    public function withdraw(WithdrawLeafApplicationRequest $request, LeafApplication $application): RedirectResponse
    {
        if ($application->status !== LeafApplication::STATUS_PENDING) {
            return $this->back($application, "Application {$application->id} isn't pending, so it can't be withdrawn.");
        }

        $application->withdraw($request->user(), $request->validated('reason'), $request->validated('withdrawn_note'));

        return $this->back($application, "Withdrew application {$application->id}.");
    }

    /**
     * Put a withdrawn application back to pending.
     */
    public function reopen(Request $request, LeafApplication $application): RedirectResponse
    {
        if (! $application->isWithdrawn()) {
            return $this->back($application, "Application {$application->id} isn't withdrawn.");
        }

        $application->reopen($request->user());

        return $this->back($application, "Reopened application {$application->id}.");
    }

    /**
     * Back to the application's page with a message.
     */
    private function back(LeafApplication $application, string $status): RedirectResponse
    {
        return redirect()->route('admin.leaves.show', $application->id)->with('status', $status);
    }
}
