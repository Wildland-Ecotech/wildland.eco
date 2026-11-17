<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteContactActivityRequest;
use App\Http\Requests\LogContactActivityRequest;
use App\Http\Requests\UpdateContactActivityRequest;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Edit;
use App\Models\LeafApplication;
use App\Reports\LeafLedgerReport;
use App\Reports\Traits\DescribesLeafApplications;
use App\Services\ContactActivityEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Editing and deleting timeline entries (/admin/activities/{id}/edit),
 * reached from contact timelines and the Leaf Ledger: every field of the
 * timeline form, plus the contact, or Unknown for drop-offs with no contact
 * yet. Changes are recorded in the edits table and listed on the page, and
 * applications follow their pickups and drop-offs (ContactActivityEditor).
 * Saving and deleting go back to the page the entry was opened from.
 */
class ContactActivityEditController extends Controller
{
    use DescribesLeafApplications;

    /**
     * How each field reads in the history.
     */
    public const FIELD_LABELS = [
        'contact_id' => 'Contact',
        'type' => 'What happened',
        'direction' => 'Who reached out',
        'happened_at' => 'When',
        'body' => 'Notes',
        'bag_count' => 'Bags',
        'bag_brand' => 'Brand',
        'drop_off_site_id' => 'Drop-off site',
        'leaf_application_id' => 'Application',
        'logged_by' => 'Logged by',
        'split_from_id' => 'Split from',
        'assigned_at' => 'Assigned',
        'assigned_by' => 'Assigned by',
    ];

    /**
     * The edit form, filled with the entry's values, with its history.
     */
    public function edit(Request $request, ContactActivity $activity, LeafLedgerReport $ledger): View
    {
        $activity->load(['contact', 'loggedBy' => fn ($query) => $query->withTrashed()]);

        return view('pages.admin.activities.edit', [
            'activity' => $activity,
            'contacts' => $this->contactOptions($activity),
            'applications' => $this->applicationOptions($activity),
            'sites' => Contact::query()->dropOffSites()->pluck('name', 'id')->all(),
            'brands' => $ledger->brands(),
            'happenedAt' => $activity->happened_at->copy()->setTimezone(LogContactActivityRequest::TIMEZONE)->format(LogContactActivityRequest::TIME_FORMAT),
            'logged' => 'Logged ' . $this->listTime($activity->created_at) . ($activity->loggedBy ? " by {$activity->loggedBy->name}" : ''),
            'history' => $this->history($activity),
            'returnTo' => $this->returnTo($activity),
            'cancelUrl' => $this->returnTo($activity) ?? $this->fallbackUrl($activity),
        ]);
    }

    /**
     * Save the entry as one edit by the admin, with the brand spelled as
     * earlier entries spell it, keeping the stored time when the minute is
     * unchanged.
     */
    public function update(UpdateContactActivityRequest $request, ContactActivity $activity, LeafLedgerReport $ledger, ContactActivityEditor $editor): RedirectResponse
    {
        $validated = $request->validated();
        $type = $validated['type'];
        $completing = in_array($type, ContactActivity::COMPLETING_TYPES, true);
        $sameMinute = $activity->happened_at->copy()->setTimezone(LogContactActivityRequest::TIMEZONE)->format(LogContactActivityRequest::TIME_FORMAT) === $validated['happened_at'];

        $fields = $editor->update($activity, [
            'contact_id' => isset($validated['contact_id']) ? (int) $validated['contact_id'] : null,
            'type' => $type,
            'direction' => in_array($type, ContactActivity::MESSAGE_TYPES, true) ? $validated['direction'] : null,
            'happened_at' => $sameMinute ? $activity->happened_at : $request->happenedAt(),
            'body' => $validated['body'] ?? null,
            'bag_count' => $completing ? (int) $validated['bag_count'] : null,
            'bag_brand' => $completing ? $ledger->brand($validated['bag_brand'] ?? null) : null,
            'drop_off_site_id' => $type === ContactActivity::TYPE_DROP_OFF ? (int) $validated['drop_off_site_id'] : null,
            'leaf_application_id' => isset($validated['leaf_application_id']) ? (int) $validated['leaf_application_id'] : null,
        ], $request->user());

        $status = $fields === [] ? 'Nothing changed.' : 'Saved ' . $this->describe($activity) . '.';

        return redirect($validated['return_to'] ?? $this->fallbackUrl($activity))->with('status', $status);
    }

    /**
     * Soft-delete the entry, recording the deletion.
     */
    public function destroy(DeleteContactActivityRequest $request, ContactActivity $activity, ContactActivityEditor $editor): RedirectResponse
    {
        $editor->delete($activity, $request->user());

        return redirect($request->validated('return_to') ?? $this->fallbackUrl($activity))
            ->with('status', 'Deleted ' . $this->describe($activity) . '.');
    }

    /**
     * Contacts to choose from by name, with their ID where names repeat,
     * including the entry's contact if deleted.
     *
     * @return array<int, string>
     */
    private function contactOptions(ContactActivity $activity): array
    {
        $contacts = Contact::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);

        if ($activity->contact !== null && $activity->contact->trashed()) {
            $contacts->push($activity->contact);
        }

        $repeated = $contacts->countBy(fn (Contact $contact) => Str::lower($contact->name))->filter(fn (int $count) => $count > 1);

        return $contacts->mapWithKeys(fn (Contact $contact) => [
            $contact->id => $contact->name
                . ($repeated->has(Str::lower($contact->name)) ? " (#{$contact->id})" : '')
                . ($contact->trashed() ? ' (deleted)' : ''),
        ])->all();
    }

    /**
     * The entry's contact's pending applications, newest first, and the
     * application it's for, labeled as on the timeline form.
     *
     * @return array<int, string>
     */
    private function applicationOptions(ContactActivity $activity): array
    {
        if ($activity->contact_id === null) {
            return [];
        }

        return LeafApplication::query()
            ->where('contact_id', $activity->contact_id)
            ->where(fn ($query) => $query->pending()->when($activity->leaf_application_id, fn ($query, int $id) => $query->orWhereKey($id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (LeafApplication $application) => [
                $application->id => "{$application->id} · {$this->leafApplicationType($application)} · applied {$this->localTime($application->created_at, 'M j')}"
                    . ($application->status === LeafApplication::STATUS_COMPLETE ? ' · complete' : ''),
            ])->all();
    }

    /**
     * The entry's edits, newest first: when, by whom, what happened, and
     * each changed field, such as "Bags: 24 → 22".
     *
     * @return list<array{when: string, who: string, action: string, changes: list<string>}>
     */
    private function history(ContactActivity $activity): array
    {
        $edits = $activity->edits()->with('editedBy')->get();

        $contactIds = $edits->flatMap(fn (Edit $edit) => collect(['contact_id', 'drop_off_site_id'])
            ->flatMap(fn (string $field) => [$edit->fields[$field]['from'] ?? null, $edit->fields[$field]['to'] ?? null]))
            ->filter()
            ->unique();
        $names = Contact::withTrashed()->whereIn('id', $contactIds)->pluck('name', 'id');

        return $edits->map(fn (Edit $edit) => [
            'when' => $this->listTime($edit->created_at),
            'who' => $edit->editedBy?->name ?? 'Unknown admin',
            'action' => Edit::ACTION_LABELS[$edit->action] ?? Str::headline($edit->action),
            'changes' => collect($edit->fields)->map(fn (array $change, string $field) => (self::FIELD_LABELS[$field] ?? Str::headline($field)) . ': '
                . $this->historyValue($field, $change['from'], $names) . ' → ' . $this->historyValue($field, $change['to'], $names))
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * A stored value as it reads in the history.
     *
     * @param  Collection<int, string>  $names
     */
    private function historyValue(string $field, mixed $value, Collection $names): string
    {
        return match (true) {
            $field === 'contact_id' && $value === null => 'Unknown',
            $value === null || $value === '' => 'none',
            in_array($field, ['contact_id', 'drop_off_site_id'], true) => $names[(int) $value] ?? "#{$value}",
            $field === 'type' => ContactActivityController::TYPE_LABELS[$value] ?? (string) $value,
            $field === 'direction' => ContactActivityController::DIRECTION_LABELS[$value] ?? (string) $value,
            in_array($field, ['happened_at', 'assigned_at'], true) => $this->listTime(Carbon::parse($value, 'UTC')),
            $field === 'leaf_application_id' => "Application {$value}",
            $field === 'body' => Str::limit((string) $value, 80),
            default => (string) $value,
        };
    }

    /**
     * The admin page the entry was opened from, to go back to, or null.
     * Never the entry's own edit page.
     */
    private function returnTo(ContactActivity $activity): ?string
    {
        $previous = parse_url(url()->previous());

        if (($previous['host'] ?? null) !== request()->getHost()) {
            return null;
        }

        $path = ($previous['path'] ?? '') . (isset($previous['query']) ? "?{$previous['query']}" : '');

        return preg_match('#^/admin(/|\?|$)#', $path) && ! str_starts_with($path, "/admin/activities/{$activity->id}") ? $path : null;
    }

    /**
     * Where to go without a page to go back to: the contact, or the Leaf
     * Ledger for the entry's season.
     */
    private function fallbackUrl(ContactActivity $activity): string
    {
        return $activity->contact_id !== null
            ? route('admin.contacts.show', $activity->contact_id)
            : route('admin.leaves.ledger', ['season' => app(LeafLedgerReport::class)->season($activity->happened_at)]);
    }

    /**
     * An entry in a few words, such as "the pickup of 28 bags from Nov 8".
     */
    private function describe(ContactActivity $activity): string
    {
        $label = Str::lower(ContactActivityController::TYPE_LABELS[$activity->type]);
        $bags = $activity->bag_count !== null ? ' of ' . number_format($activity->bag_count) . ' ' . Str::plural('bag', $activity->bag_count) : '';

        return "the {$label}{$bags} from {$this->localTime($activity->happened_at, 'M j')}";
    }
}
