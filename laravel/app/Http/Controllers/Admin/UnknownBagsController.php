<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogContactActivityRequest;
use App\Http\Requests\LogUnknownBagsRequest;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Reports\LeafLedgerReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Logging bags found at drop-off sites with no known donor
 * (/admin/leaves/ledger/unknown): drop-offs with no contact yet, listed on
 * the Leaf Ledger as Unknown until assigned. Save goes back to the ledger;
 * Save and Add Another comes back to the form with the same time and site,
 * for piles with more than one brand of bags.
 */
class UnknownBagsController extends Controller
{
    /**
     * The form: the drop-off sites, earlier bag brands as suggestions, and
     * now as the time.
     */
    public function create(LeafLedgerReport $ledger): View
    {
        return view('pages.admin.leaves.unknown', [
            'sites' => Contact::query()->dropOffSites()->pluck('name', 'id')->all(),
            'brands' => $ledger->brands(),
            'now' => Carbon::now(LogContactActivityRequest::TIMEZONE)->format(LogContactActivityRequest::TIME_FORMAT),
        ]);
    }

    /**
     * Log the drop-off with no contact, recording which admin logged it,
     * with the brand spelled as earlier entries spell it.
     */
    public function store(LogUnknownBagsRequest $request, LeafLedgerReport $ledger): RedirectResponse
    {
        $validated = $request->validated();

        $entry = new ContactActivity([
            'type' => ContactActivity::TYPE_DROP_OFF,
            'happened_at' => $request->happenedAt(),
            'body' => $validated['body'] ?? null,
            'bag_count' => (int) $validated['bag_count'],
            'bag_brand' => $ledger->brand($validated['bag_brand'] ?? null),
            'drop_off_site_id' => (int) $validated['drop_off_site_id'],
        ]);
        $entry->forceFill(['logged_by' => $request->user()->id])->save();

        $status = 'Logged ' . number_format($entry->bag_count) . ' unknown ' . Str::plural('bag', $entry->bag_count) . " at {$entry->dropOffSite->name}.";

        if ($request->boolean('add_another')) {
            return redirect()->route('admin.leaves.ledger.unknown.create')
                ->with('status', $status)
                // The same time and drop-off site
                ->withInput([
                    'happened_at' => $validated['happened_at'],
                    'drop_off_site_id' => $validated['drop_off_site_id'],
                ]);
        }

        return redirect()->route('admin.leaves.ledger', ['season' => $ledger->season($entry->happened_at)])->with('status', $status);
    }
}
