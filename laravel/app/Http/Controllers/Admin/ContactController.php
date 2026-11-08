<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\ShowsFollowUps;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteContactRequest;
use App\Http\Requests\SaveContactRequest;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactDetail;
use App\Models\FormSubmission;
use App\Models\LeafApplication;
use App\Reports\ContactReport;
use App\Reports\FollowUpReport;
use App\Reports\FormSubmissionReport;
use App\Reports\Traits\DescribesFollowUps;
use App\Reports\Traits\DescribesFormSubmissions;
use App\Reports\Traits\DescribesLeafApplications;
use App\Services\ContactMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin contact pages (/admin/contacts): the list of every contact,
 * with those who need follow-up first, who needs to hear from us first at
 * the top, or sorted by the person who applied most recently, and a search
 * by name, phone number, or email; one contact with their details,
 * timeline, applications, and form errors; adding a contact by hand, such
 * as someone reached by text before they applied, or a drop-off site;
 * editing a contact's name, notes, and whether they're a drop-off site;
 * deleting a contact with no applications, such as one added as a test;
 * merging another contact into one; and the applications whose contact
 * needs an admin to look (Needs Review). Their phone numbers, emails, and
 * address are edited through ContactDetailController, and timeline entries
 * logged through ContactActivityController.
 */
class ContactController extends Controller
{
    use DescribesFollowUps, DescribesFormSubmissions, DescribesLeafApplications, ShowsFollowUps;

    /**
     * How the list is sorted: those who need follow-up first, in the
     * follow-up order, then everyone else with the person who applied most
     * recently first (by default), or everyone that way.
     */
    public const SORT_FOLLOW_UP = 'follow-up';

    public const SORT_RECENT = 'recent';

    public const SORT_CHOICES = [
        self::SORT_FOLLOW_UP => 'Needs follow-up first',
        self::SORT_RECENT => 'Most recent application',
    ];

    /**
     * The list's columns, the ones that make up a contact's line on a phone
     * (name, and why when they need follow-up), and the columns lined up
     * on the right (bags and applications).
     */
    public const HEADERS = ['Name', 'Applied for', 'Follow-up', 'Bags pending', 'Bags donated', 'Applications', 'Latest application'];

    public const SUMMARY_COLUMNS = [0, 2];

    public const NUMBER_COLUMNS = [3, 4, 5];

    /**
     * The columns of a contact's applications, the ones that make up an
     * application's line on a phone (applied, type, and bags), and the
     * columns lined up on the right (ID and bags).
     */
    public const APPLICATION_HEADERS = ['ID', 'Applied', 'Type', 'Bags', 'Ready', 'Status'];

    public const APPLICATION_SUMMARY_COLUMNS = [1, 2, 3];

    public const APPLICATION_NUMBER_COLUMNS = [0, 3];

    /**
     * The columns of a contact's form errors, and the ones that make up a
     * submission's line on a phone (submitted, form, and what happened).
     */
    public const FORM_ERROR_HEADERS = ['Reference', 'Submitted', 'Form', 'What happened', 'Follow-up'];

    public const FORM_ERROR_SUMMARY_COLUMNS = [1, 2, 3];

    /**
     * The Needs Review list's columns, the ones that make up an
     * application's line on a phone (applied, name, and why), and the column
     * lined up on the right (ID).
     */
    public const REVIEW_HEADERS = ['ID', 'Applied', 'Name', 'Contact', 'Why'];

    public const REVIEW_SUMMARY_COLUMNS = [1, 2, 4];

    public const REVIEW_NUMBER_COLUMNS = [0];

    /**
     * The longest search the list takes; anything longer is cut short.
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * Every contact matching the search in the query string, with whether
     * their latest application is for a pickup or drop-off, their group and
     * why when they need follow-up, and their bags and applications: those
     * who need follow-up first, in the follow-up order, then everyone else,
     * or with `sort=recent`, everyone by their latest application.
     */
    public function index(Request $request, ContactReport $report, FollowUpReport $followUpReport): View
    {
        $search = $request->query('q');
        $search = is_string($search) ? Str::limit(trim($search), self::MAX_SEARCH_LENGTH, '') : '';
        $sort = $request->query('sort') === self::SORT_RECENT ? self::SORT_RECENT : self::SORT_FOLLOW_UP;

        $contacts = $report->list($search);
        $byId = $contacts->keyBy('id');
        // Only the contacts the search matched, and none deleted
        $followUps = $this->followUpsByContact($followUpReport)->filter(fn (array $item, int $id) => $byId->has($id));

        $ordered = $sort === self::SORT_FOLLOW_UP
            ? $followUps->keys()->map(fn (int $id) => $byId[$id])->concat($contacts->reject(fn (Contact $contact) => $followUps->has($contact->id)))
            : $contacts;

        return view('pages.admin.contacts.index', [
            'search' => $search,
            'sort' => $sort,
            'reviewCount' => LeafApplication::needingContactReview()->count(),
            'summary' => $this->summary($contacts->count(), $followUps->count(), $search, $sort),
            'headers' => self::HEADERS,
            'summaryColumns' => self::SUMMARY_COLUMNS,
            'numeric' => self::NUMBER_COLUMNS,
            'rows' => $ordered->map(fn (Contact $contact) => $this->row($contact, $followUps->get($contact->id)))->values()->all(),
        ]);
    }

    /**
     * One contact with their details, the other contacts sharing a phone
     * number or email, their timeline, their applications, and their form
     * errors. A contact merged into another goes to that one.
     */
    public function show(string $contact, ContactReport $report, FormSubmissionReport $submissions, FollowUpReport $followUpReport): View|RedirectResponse
    {
        // The route only matches digits
        $record = $report->find((int) $contact);

        if ($record === null) {
            $into = $report->mergedInto((int) $contact);

            abort_if($into === null, 404);

            return redirect()->route('admin.contacts.show', $into->id)->with('status', 'That contact was merged into ' . $into->name . '.');
        }

        $record->load(['activities' => fn ($query) => $query->with('loggedBy')->orderByDesc('happened_at')->orderByDesc('id')]);
        $formErrors = $submissions->forContact($record);

        return view('pages.admin.contacts.show', [
            'contact' => $record,
            'fields' => $this->fields($record, $this->followUpsByContact($followUpReport)->get($record->id)),
            'sharing' => $report->sharing($record),
            'timeline' => $this->timeline($record, $formErrors),
            'applicationHeaders' => self::APPLICATION_HEADERS,
            'applicationRows' => $record->applications->map(fn (LeafApplication $application) => [
                'cells' => [
                    $application->id,
                    $this->listTime($application->created_at),
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count,
                    $this->bagsReady($application),
                    Str::ucfirst($application->status),
                ],
                'summary' => [
                    $this->shortDate($application->created_at),
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count),
                ],
                'url' => route('admin.leaves.show', $application->id),
            ])->all(),
            'formErrorHeaders' => self::FORM_ERROR_HEADERS,
            'formErrorRows' => $formErrors->map(function (FormSubmission $submission) {
                $fields = $this->formSubmissionFields($submission);

                return [
                    'cells' => [
                        $submission->reference,
                        $this->listTime($submission->created_at),
                        $fields['Form'],
                        $fields['What happened'],
                        $this->followUpCell($submission, $this->formSubmissionFollowUpText($submission)),
                    ],
                    'summary' => [$this->shortDate($submission->created_at), $fields['Form'], $fields['What happened']],
                    'status' => $this->followUpIcon($submission)['status'] ?? null,
                    'statusLabel' => $this->followUpIcon($submission)['label'] ?? null,
                    'url' => route('admin.errors.show', $submission->reference),
                ];
            })->all(),
        ]);
    }

    /**
     * Applications whose contact needs an admin to look, oldest first, with
     * the contact each is linked to and why.
     */
    public function review(): View
    {
        $applications = LeafApplication::needingContactReview()->with('contact')->orderBy('created_at')->orderBy('id')->get();
        $count = $applications->count();

        return view('pages.admin.contacts.review', [
            'summary' => $count === 0
                ? 'Nothing needs review.'
                : "{$count} " . Str::plural('application', $count) . ' ' . ($count === 1 ? 'needs' : 'need') . ' review, oldest first.',
            'headers' => self::REVIEW_HEADERS,
            'rows' => $applications->map(function (LeafApplication $application) {
                $why = collect($application->contact_review)->map(fn (string $reason) => LeafApplication::REVIEW_LABELS[$reason] ?? $reason)->implode('; ');

                return [
                    'cells' => [
                        $application->id,
                        $this->listTime($application->created_at),
                        $application->name,
                        $application->contact
                            ? ['text' => $application->contact->name, 'url' => route('admin.contacts.show', $application->contact_id)]
                            : 'Not linked',
                        $why,
                    ],
                    'summary' => [$this->shortDate($application->created_at), $application->name, $why],
                    'url' => route('admin.leaves.show', $application->id),
                ];
            })->all(),
        ]);
    }

    /**
     * The form for adding a contact by hand.
     */
    public function create(): View
    {
        return view('pages.admin.contacts.create');
    }

    /**
     * Add a contact. When another contact already has the phone number or
     * email, nothing is added: the form comes back naming them, with a box
     * to add a separate contact anyway.
     */
    public function store(SaveContactRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $duplicates = $this->duplicates($validated['phone'] ?? null, $validated['email'] ?? null);

        if ($duplicates !== [] && ! $request->boolean('separate')) {
            return back()->withInput()->with('duplicates', $duplicates);
        }

        $contact = DB::transaction(function () use ($request, $validated) {
            $contact = Contact::create([
                'name' => $validated['name'],
                'notes' => $validated['notes'] ?? null,
                'is_drop_off_site' => $request->boolean('is_drop_off_site'),
            ]);

            foreach ([ContactDetail::TYPE_PHONE, ContactDetail::TYPE_EMAIL] as $type) {
                if (($validated[$type] ?? null) !== null) {
                    $contact->usePrimary($type, $validated[$type]);
                }
            }

            if (($validated['address'] ?? null) !== null) {
                $contact->moveTo($validated['address']);
            }

            return $contact;
        });

        return redirect()->route('admin.contacts.show', $contact->id)->with('status', "Added {$contact->name}.");
    }

    /**
     * The form for editing a contact: their name and notes, their phone
     * numbers, emails, and address, each with its own buttons, and deleting
     * them when no application belongs to them, even a deleted one.
     */
    public function edit(Contact $contact): View
    {
        $details = $contact->details()->current()->orderByDesc('is_primary')->orderBy('id')->get();

        return view('pages.admin.contacts.edit', [
            'contact' => $contact,
            'phones' => $details->where('type', ContactDetail::TYPE_PHONE)->values(),
            'emails' => $details->where('type', ContactDetail::TYPE_EMAIL)->values(),
            'address' => $details->firstWhere('type', ContactDetail::TYPE_ADDRESS),
            'applicationCount' => $contact->applications()->withTrashed()->count(),
        ]);
    }

    /**
     * Save a contact's name, notes, and whether they're a drop-off site.
     */
    public function update(SaveContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update([
            'name' => $request->validated('name'),
            'notes' => $request->validated('notes'),
            'is_drop_off_site' => $request->boolean('is_drop_off_site'),
        ]);

        return redirect()->route('admin.contacts.edit', $contact->id)->with('status', 'Saved the name and notes.');
    }

    /**
     * Delete a contact with no applications, such as one added as a test,
     * and go back to Contacts. Like a merged contact, it's only soft-deleted,
     * and left out of every list, the map, and matching new applications.
     */
    public function destroy(DeleteContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->delete();

        return redirect()->route('admin.contacts')->with('status', "Deleted {$contact->name}.");
    }

    /**
     * Merge another contact into this one: their applications, details,
     * and notes move here, and their contact is deleted.
     */
    public function merge(Request $request, Contact $contact, Contact $other, ContactMerger $merger): RedirectResponse
    {
        abort_if($other->is($contact), 404);

        $moved = $merger->merge($other, $contact, $request->user());

        return redirect()->route('admin.contacts.show', $contact->id)->with(
            'status',
            "Merged {$other->name} into {$contact->name}" . ($moved > 0 ? ', with ' . $moved . ' ' . Str::plural('application', $moved) : '') . '.',
        );
    }

    /**
     * Other contacts, not deleted, with this phone number or email, current
     * or previous: each with the value as entered (a phone number
     * formatted), what it is, whose it is, whether it's a previous one of
     * theirs, and a link to them.
     *
     * @return list<array{value: string, kind: string, name: string, previous: bool, url: string}>
     */
    private function duplicates(?string $phone, ?string $email): array
    {
        $duplicates = [];

        foreach ([ContactDetail::TYPE_PHONE => [$phone, 'phone number'], ContactDetail::TYPE_EMAIL => [$email, 'email']] as $type => [$value, $kind]) {
            $normalized = ContactDetail::normalize($type, $value);

            if ($normalized === null) {
                continue;
            }

            ContactDetail::query()
                ->with('contact')
                ->where('type', $type)
                ->where('normalized', $normalized)
                ->whereHas('contact', fn ($query) => $query->whereNull('deleted_at'))
                ->orderByRaw('removed_at IS NOT NULL')
                ->get()
                ->unique('contact_id')
                ->each(function (ContactDetail $detail) use (&$duplicates, $type, $value, $kind) {
                    $duplicates[] = [
                        'value' => $type === ContactDetail::TYPE_PHONE ? ContactDetail::formatPhone($value) : trim($value),
                        'kind' => $kind,
                        'name' => $detail->contact->name,
                        'previous' => $detail->isRemoved(),
                        'url' => route('admin.contacts.show', $detail->contact_id),
                    ];
                });
        }

        return $duplicates;
    }

    /**
     * The line saying what the list shows: how many contacts, and with
     * those who need follow-up first, how many of them there are.
     */
    private function summary(int $count, int $followUpCount, string $search, string $sort): string
    {
        if ($count === 0) {
            return $search === '' ? 'No contacts yet.' : "No contacts match “{$search}”.";
        }

        $contacts = "{$count} " . Str::plural('contact', $count) . ($search === '' ? '' : " matching “{$search}”");

        return match (true) {
            $sort === self::SORT_RECENT => "{$contacts}, most recent application first.",
            $followUpCount === 0 => "{$contacts}, most recent application first. Nobody is waiting for a pickup or drop-off.",
            $followUpCount === 1 => "{$contacts}. The one waiting for a pickup or drop-off is first.",
            default => "{$contacts}. The {$followUpCount} waiting for a pickup or drop-off are first, who needs to hear from us first at the top.",
        };
    }

    /**
     * A contact, for the list: their name, with their follow-up group
     * beside it when they need follow-up (or "Drop-off site" for one of
     * ours), whether their latest application is for a pickup or drop-off,
     * why they need follow-up, the bags on their pending applications and
     * the bags they've given, their number of applications, and when they
     * last applied.
     *
     * @param  array{application: LeafApplication, tier: string, reason: string, last_reached_at: ?\Carbon\CarbonInterface}|null  $followUp
     * @return array<string, mixed>
     */
    private function row(Contact $contact, ?array $followUp): array
    {
        $appliedAt = $contact->applications_max_created_at;
        $why = $followUp !== null ? $this->followUpReason($followUp) : '';

        return [
            'cells' => [
                $contact->name,
                match ($contact->latest_application_type) {
                    LeafApplication::TYPE_PICKUP => 'Pickup',
                    LeafApplication::TYPE_DROP_OFF => 'Drop-off',
                    default => '',
                },
                $why,
                $contact->bags_pending ?? '',
                $contact->bags_donated ?? '',
                $contact->applications_count,
                $appliedAt ? $this->listTime($appliedAt) : '',
            ],
            // Name and why for those who need follow-up, or when they last applied and name
            'summary' => $followUp !== null
                ? [$contact->name, $why]
                : array_values(array_filter([$appliedAt ? $this->shortDate($appliedAt) : '', $contact->name])),
            'badge' => match (true) {
                $followUp !== null => self::FOLLOW_UP_GROUP_LABELS[$followUp['tier']],
                $contact->is_drop_off_site => 'Drop-off site',
                default => null,
            },
            'badgeTone' => $followUp !== null ? self::FOLLOW_UP_GROUP_TONES[$followUp['tier']] : null,
            'url' => route('admin.contacts.show', $contact->id),
        ];
    }

    /**
     * A contact's details for their page: phone numbers and emails, one per
     * line with the main one first, the current address, linking to the
     * map, any previous phone numbers, emails, and addresses, whether
     * they're a drop-off site, their follow-up group and why when they have
     * a pending application, notes, and when they were added.
     *
     * @param  array{application: LeafApplication, tier: string, reason: string, last_reached_at: ?\Carbon\CarbonInterface}|null  $followUp
     * @return array<string, string|array{text: string, url: string}>
     */
    private function fields(Contact $contact, ?array $followUp): array
    {
        $current = $contact->details->reject(fn (ContactDetail $detail) => $detail->isRemoved());
        $removed = $contact->details->filter(fn (ContactDetail $detail) => $detail->isRemoved());

        $fields = [
            'Phone' => $this->detailLines($current->where('type', ContactDetail::TYPE_PHONE)),
            'Email' => $this->detailLines($current->where('type', ContactDetail::TYPE_EMAIL)),
        ];

        // The address links to the map, showing them with the drop-off sites
        $address = $current->firstWhere('type', ContactDetail::TYPE_ADDRESS);
        $fields['Address'] = $address ? ['text' => $address->value, 'url' => route('admin.map', ['contact' => $contact->id])] : '';

        foreach ([ContactDetail::TYPE_PHONE => 'phone number', ContactDetail::TYPE_EMAIL => 'email', ContactDetail::TYPE_ADDRESS => 'address'] as $type => $name) {
            $previous = $removed->where('type', $type);

            if ($previous->isNotEmpty()) {
                $fields['Previous ' . Str::plural($name, $previous->count())] = $previous->map(fn (ContactDetail $detail) => $detail->displayValue())->implode("\n");
            }
        }

        if ($contact->is_drop_off_site) {
            $fields['Drop-off site'] = 'Yes';
        }

        if ($followUp !== null) {
            $fields['Follow-up'] = self::FOLLOW_UP_GROUP_LABELS[$followUp['tier']] . ' · ' . $this->followUpReason($followUp);
        }

        return [
            ...$fields,
            'Notes' => (string) $contact->notes,
            'Added' => $this->localTime($contact->created_at),
        ];
    }

    /**
     * The contact's timeline, newest first: their logged entries, then, added
     * automatically, their applications and form errors. Each has when it
     * happened, whether it came from them, whether it was added
     * automatically, what happened, what was said, and a link to the record
     * it's about with any words after the link. Activities must be loaded.
     *
     * @param  Collection<int, FormSubmission>  $formErrors
     * @return list<array{when: string, incoming: bool, automatic: bool, what: string, body: ?string, link: ?array{text: string, url: string}, after: string}>
     */
    private function timeline(Contact $contact, Collection $formErrors): array
    {
        $applicationLink = fn (int $id) => ['text' => "Application {$id}", 'url' => route('admin.leaves.show', $id)];

        $activities = $contact->activities->map(fn (ContactActivity $activity) => [
            'time' => $activity->happened_at,
            'incoming' => $activity->direction === ContactActivity::DIRECTION_INCOMING,
            'automatic' => false,
            'what' => $this->activityTitle($contact, $activity),
            'body' => $activity->body,
            'link' => $activity->leaf_application_id !== null ? $applicationLink($activity->leaf_application_id) : null,
            'after' => '',
        ]);

        $applications = $contact->applications->map(fn (LeafApplication $application) => [
            'time' => $application->created_at,
            'incoming' => false,
            'automatic' => true,
            'what' => ($application->type === LeafApplication::TYPE_PICKUP ? 'Applied for a pickup: ' : 'Applied to drop off: ')
                . $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count) . ', '
                . ($application->ready_now ? 'ready now' : 'ready ' . $application->ready_date->format('M j')),
            'body' => null,
            'link' => $applicationLink($application->id),
            'after' => '',
        ]);

        $errors = $formErrors->map(function (FormSubmission $submission) {
            $fields = $this->formSubmissionFields($submission);

            return [
                'time' => $submission->created_at,
                'incoming' => false,
                'automatic' => true,
                'what' => 'Form error: ' . Str::lower($fields['Form']) . ', ' . Str::lcfirst($fields['What happened']),
                'body' => null,
                'link' => ['text' => $submission->reference, 'url' => route('admin.errors.show', $submission->reference)],
                'after' => ' · ' . Str::lcfirst($this->formSubmissionFollowUpText($submission)),
            ];
        });

        // A stable sort, so entries at the same minute stay in this order
        return $activities->concat($applications)->concat($errors)
            ->sortByDesc(fn (array $entry) => $entry['time']->getTimestamp())
            ->map(fn (array $entry) => ['when' => $this->listTime($entry['time']), ...Arr::except($entry, 'time')])
            ->values()
            ->all();
    }

    /**
     * What a logged entry was, such as "Text from us · Rachel", "Call from
     * Jamie Rivera", or "Pickup: 28 bags · Rachel", with the admin who
     * logged it when they were the one in touch.
     */
    private function activityTitle(Contact $contact, ContactActivity $activity): string
    {
        $label = ContactActivityController::TYPE_LABELS[$activity->type] ?? $activity->type;
        $by = $activity->loggedBy ? ' · ' . ($activity->loggedBy->name ?: $activity->loggedBy->email) : '';

        return match (true) {
            $activity->direction === ContactActivity::DIRECTION_INCOMING => "{$label} from {$contact->name}",
            $activity->direction === ContactActivity::DIRECTION_OUTGOING => "{$label} from us{$by}",
            $activity->bag_count !== null => "{$label}: {$activity->bag_count} " . Str::plural('bag', $activity->bag_count) . $by,
            default => $label . $by,
        };
    }

    /**
     * Current details of one type, one per line, each with its label, and
     * "main" on the primary one when there's more than one.
     *
     * @param  Collection<int, ContactDetail>  $details
     */
    private function detailLines(Collection $details): string
    {
        $several = $details->count() > 1;

        return $details->map(function (ContactDetail $detail) use ($several) {
            $notes = array_filter([$detail->label, $several && $detail->is_primary ? 'main' : null]);

            return $detail->displayValue() . ($notes === [] ? '' : ' (' . implode(', ', $notes) . ')');
        })->implode("\n");
    }
}
