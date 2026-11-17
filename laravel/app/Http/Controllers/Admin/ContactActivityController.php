<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogContactActivityRequest;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Reports\LeafLedgerReport;
use App\Reports\Traits\DescribesLeafApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Logging an entry on a contact's timeline (/admin/contacts/{id}/activities):
 * a text or call, a visit, a note, or a pickup or drop-off, which marks its
 * application complete, with the bag brand and, for drop-offs, the drop-off
 * site. Save goes back to the contact; Save and Add Another comes back to
 * the form, for the reply in a back-and-forth.
 */
class ContactActivityController extends Controller
{
    use DescribesLeafApplications;

    /**
     * How each kind of entry is named on the form and the timeline.
     */
    public const TYPE_LABELS = [
        ContactActivity::TYPE_TEXT => 'Text',
        ContactActivity::TYPE_CALL => 'Call',
        ContactActivity::TYPE_VISIT => 'Visit',
        ContactActivity::TYPE_NOTE => 'Note',
        ContactActivity::TYPE_PICKUP => 'Pickup',
        ContactActivity::TYPE_DROP_OFF => 'Drop-off',
    ];

    public const DIRECTION_LABELS = [
        ContactActivity::DIRECTION_OUTGOING => 'We did',
        ContactActivity::DIRECTION_INCOMING => 'They did',
    ];

    /**
     * The form: their pending applications to choose from, newest first,
     * the drop-off sites, earlier bag brands as suggestions, and now as the
     * time.
     */
    public function create(Contact $contact, LeafLedgerReport $ledger): View
    {
        $applications = $contact->applications()->pending()->orderByDesc('created_at')->orderByDesc('id')->get();

        return view('pages.admin.contacts.log', [
            'contact' => $contact,
            'phone' => $contact->primary(ContactDetail::TYPE_PHONE)?->displayValue(),
            'applications' => $applications->mapWithKeys(fn (LeafApplication $application) => [
                $application->id => "{$application->id} · {$this->leafApplicationType($application)} · applied {$application->created_at->copy()->setTimezone(config('organization.timezone'))->format('M j')}",
            ])->all(),
            'sites' => Contact::query()->dropOffSites()->pluck('name', 'id')->all(),
            'brands' => $ledger->brands(),
            'now' => Carbon::now(LogContactActivityRequest::TIMEZONE)->format(LogContactActivityRequest::TIME_FORMAT),
        ]);
    }

    /**
     * Log the entry, recording which admin logged it, with the brand
     * spelled as earlier entries spell it. A follow-up date goes on the
     * application chosen, or on all their pending applications when none
     * is.
     */
    public function store(LogContactActivityRequest $request, Contact $contact, LeafLedgerReport $ledger): RedirectResponse
    {
        $validated = $request->validated();
        $type = $validated['type'];
        $completing = in_array($type, ContactActivity::COMPLETING_TYPES, true);

        $activity = DB::transaction(function () use ($request, $contact, $ledger, $validated, $type, $completing) {
            $activity = $contact->activities()->make([
                'type' => $type,
                'direction' => in_array($type, ContactActivity::MESSAGE_TYPES, true) ? $validated['direction'] : null,
                'happened_at' => $request->happenedAt(),
                'body' => $validated['body'] ?? null,
                'bag_count' => $completing ? (int) $validated['bag_count'] : null,
                'bag_brand' => $completing ? $ledger->brand($validated['bag_brand'] ?? null) : null,
                'drop_off_site_id' => $type === ContactActivity::TYPE_DROP_OFF ? (int) $validated['drop_off_site_id'] : null,
                'leaf_application_id' => $validated['leaf_application_id'] ?? null,
            ]);
            $activity->forceFill(['logged_by' => $request->user()->id])->save();

            if (($validated['follow_up_on'] ?? null) !== null) {
                $contact->applications()->pending()
                    ->when($activity->leaf_application_id, fn ($query, int $id) => $query->whereKey($id))
                    ->update(['follow_up_on' => $validated['follow_up_on']]);
            }

            return $activity;
        });

        $status = 'Logged ' . $this->describe($activity) . '.';

        if ($request->boolean('add_another')) {
            return redirect()->route('admin.contacts.activities.create', $contact->id)
                ->with('status', $status)
                // The same kind, direction, drop-off site, and application, None included
                ->withInput([
                    'type' => $type,
                    'direction' => $validated['direction'] ?? ContactActivity::DIRECTION_OUTGOING,
                    'drop_off_site_id' => $validated['drop_off_site_id'] ?? null,
                    'leaf_application_id' => $validated['leaf_application_id'] ?? null,
                ]);
        }

        return redirect()->route('admin.contacts.show', $contact->id)->with('status', $status);
    }

    /**
     * An entry in a few words, such as "a text" or "a pickup of 28 bags".
     */
    private function describe(ContactActivity $activity): string
    {
        $label = Str::lower(self::TYPE_LABELS[$activity->type]);
        $bags = $activity->bag_count !== null ? ' of ' . $activity->bag_count . ' ' . Str::plural('bag', $activity->bag_count) : '';

        return "a {$label}{$bags}";
    }
}
