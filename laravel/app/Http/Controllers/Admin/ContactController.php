<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveContactRequest;
use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Reports\ContactReport;
use App\Reports\Traits\DescribesLeafApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin contact pages (/admin/contacts): the list of contacts, with the
 * person who applied most recently first and a search by name, phone number,
 * or email; one contact with their details and applications; adding a
 * contact by hand, such as someone reached by text before they applied; and
 * editing a contact's name and notes. Their phone numbers, emails, and
 * address are edited through ContactDetailController.
 */
class ContactController extends Controller
{
    use DescribesLeafApplications;

    /**
     * The list's columns, the ones that make up a contact's line on a phone
     * after the date they last applied (name and phone), and the column
     * lined up on the right (applications).
     */
    public const HEADERS = ['Name', 'Phone', 'Email', 'Applications', 'Latest application'];

    public const SUMMARY_COLUMNS = [0, 1];

    public const NUMBER_COLUMNS = [3];

    /**
     * The columns of a contact's applications, the ones that make up an
     * application's line on a phone (applied, type, and bags), and the
     * columns lined up on the right (ID and bags).
     */
    public const APPLICATION_HEADERS = ['ID', 'Applied', 'Type', 'Bags', 'Ready'];

    public const APPLICATION_SUMMARY_COLUMNS = [1, 2, 3];

    public const APPLICATION_NUMBER_COLUMNS = [0, 3];

    /**
     * The longest search the list takes; anything longer is cut short.
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * The list of contacts, matching the search in the query string.
     */
    public function index(Request $request, ContactReport $report): View
    {
        $search = $request->query('q');
        $search = is_string($search) ? Str::limit(trim($search), self::MAX_SEARCH_LENGTH, '') : '';

        $contacts = $report->list($search);

        return view('pages.admin.contacts.index', [
            'search' => $search,
            'summary' => $this->summary($contacts->count(), $search),
            'headers' => self::HEADERS,
            'rows' => $contacts->map(function (Contact $contact) {
                $phone = $this->primaryValue($contact->details, ContactDetail::TYPE_PHONE);
                $appliedAt = $contact->applications_max_created_at;

                return [
                    'cells' => [
                        $contact->name,
                        $phone,
                        $this->primaryValue($contact->details, ContactDetail::TYPE_EMAIL),
                        $contact->applications_count,
                        $appliedAt ? $this->listTime($appliedAt) : '',
                    ],
                    'summary' => array_values(array_filter([
                        $appliedAt ? $this->shortDate($appliedAt) : '',
                        $contact->name,
                        $phone,
                    ])),
                    'url' => route('admin.contacts.show', $contact->id),
                ];
            })->all(),
        ]);
    }

    /**
     * One contact with their details and applications.
     */
    public function show(string $contact, ContactReport $report): View
    {
        // The route only matches digits
        $record = $report->find((int) $contact);

        abort_if($record === null, 404);

        return view('pages.admin.contacts.show', [
            'contact' => $record,
            'fields' => $this->fields($record),
            'applicationHeaders' => self::APPLICATION_HEADERS,
            'applicationRows' => $record->applications->map(fn (LeafApplication $application) => [
                'cells' => [
                    $application->id,
                    $this->listTime($application->created_at),
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count,
                    $this->bagsReady($application),
                ],
                'summary' => [
                    $this->shortDate($application->created_at),
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count),
                ],
                'url' => route('admin.leaves.show', $application->id),
            ])->all(),
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

        $contact = DB::transaction(function () use ($validated) {
            $contact = Contact::create(['name' => $validated['name'], 'notes' => $validated['notes'] ?? null]);

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
     * The form for editing a contact: their name and notes, and their phone
     * numbers, emails, and address, each with its own buttons.
     */
    public function edit(Contact $contact): View
    {
        $details = $contact->details()->current()->orderByDesc('is_primary')->orderBy('id')->get();

        return view('pages.admin.contacts.edit', [
            'contact' => $contact,
            'phones' => $details->where('type', ContactDetail::TYPE_PHONE)->values(),
            'emails' => $details->where('type', ContactDetail::TYPE_EMAIL)->values(),
            'address' => $details->firstWhere('type', ContactDetail::TYPE_ADDRESS),
        ]);
    }

    /**
     * Save a contact's name and notes.
     */
    public function update(SaveContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update([
            'name' => $request->validated('name'),
            'notes' => $request->validated('notes'),
        ]);

        return redirect()->route('admin.contacts.edit', $contact->id)->with('status', 'Saved the name and notes.');
    }

    /**
     * Other contacts, not deleted, with this phone number or email, current
     * or previous: each with the value as entered, what it is, whose it is,
     * whether it's a previous one of theirs, and a link to them.
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
                ->each(function (ContactDetail $detail) use (&$duplicates, $value, $kind) {
                    $duplicates[] = [
                        'value' => trim($value),
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
     * The line saying what the list shows.
     */
    private function summary(int $count, string $search): string
    {
        if ($search !== '') {
            return $count === 0
                ? "No contacts match “{$search}”."
                : "{$count} " . Str::plural('contact', $count) . " matching “{$search}”, most recent application first.";
        }

        return $count === 0
            ? 'No contacts yet.'
            : "{$count} " . Str::plural('contact', $count) . ', most recent application first.';
    }

    /**
     * A contact's details for their page: phone numbers and emails, one per
     * line with the main one first, the current address, any previous
     * phone numbers, emails, and addresses, notes, and when they were added.
     *
     * @return array<string, string>
     */
    private function fields(Contact $contact): array
    {
        $current = $contact->details->reject(fn (ContactDetail $detail) => $detail->isRemoved());
        $removed = $contact->details->filter(fn (ContactDetail $detail) => $detail->isRemoved());

        $fields = [
            'Phone' => $this->detailLines($current->where('type', ContactDetail::TYPE_PHONE)),
            'Email' => $this->detailLines($current->where('type', ContactDetail::TYPE_EMAIL)),
            'Address' => (string) $current->firstWhere('type', ContactDetail::TYPE_ADDRESS)?->value,
        ];

        foreach ([ContactDetail::TYPE_PHONE => 'phone number', ContactDetail::TYPE_EMAIL => 'email', ContactDetail::TYPE_ADDRESS => 'address'] as $type => $name) {
            $previous = $removed->where('type', $type);

            if ($previous->isNotEmpty()) {
                $fields['Previous ' . Str::plural($name, $previous->count())] = $previous->pluck('value')->implode("\n");
            }
        }

        return [
            ...$fields,
            'Notes' => (string) $contact->notes,
            'Added' => $this->localTime($contact->created_at),
        ];
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

            return $detail->value . ($notes === [] ? '' : ' (' . implode(', ', $notes) . ')');
        })->implode("\n");
    }

    /**
     * The value of a contact's primary detail of a type, or their first
     * current one, or an empty string. Details come primary first.
     *
     * @param  Collection<int, ContactDetail>  $details
     */
    private function primaryValue(Collection $details, string $type): string
    {
        return (string) $details->firstWhere('type', $type)?->value;
    }
}
