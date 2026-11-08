@use('App\Http\Controllers\Admin\ContactController')

<x-admin-page :title="$contact->name" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                {{-- The address links to the map --}}
                <dd>@if (is_array($value))<a href="{{ $value['url'] }}">{{ $value['text'] }}</a>@else{{ $value === '' ? '—' : $value }}@endif</dd>
            </div>
        @endforeach
    </dl>

    <div class="record-actions">
        <a class="button dark small" href="{{ route('admin.contacts.edit', $contact->id) }}">Edit Contact</a>
    </div>

    {{-- Other contacts with one of this contact's phone numbers or emails, to merge here if they're the same person --}}
    @if ($sharing->isNotEmpty())
        <h2>Shares Details With</h2>

        <ul class="detail-list">
            @foreach ($sharing as $shared)
                <li>
                    <span class="detail-value"><a href="{{ route('admin.contacts.show', $shared['contact']->id) }}">{{ $shared['contact']->name }}</a> <span class="detail-note">· {{ implode(', ', $shared['values']) }}</span></span>

                    <form method="POST" action="{{ route('admin.contacts.merge', [$contact->id, $shared['contact']->id]) }}">
                        @csrf
                        <button type="submit" class="button dark small">Merge Into {{ $contact->name }}</button>
                    </form>
                </li>
            @endforeach
        </ul>

        <p class="detail-hint">Merging moves their applications and details to {{ $contact->name }}, keeps a second address or main number as a previous one, adds their notes, and deletes their contact. Leave them apart if they’re different people.</p>
    @endif

    {{-- Logged texts, calls, visits, notes, pickups, and drop-offs, with their applications and form errors, newest first --}}
    <h2>Timeline</h2>

    <div class="record-actions">
        <a class="button dark small" href="{{ route('admin.contacts.activities.create', $contact->id) }}">Log Activity</a>
    </div>

    @if ($timeline === [])
        <p class="summary">Nothing logged yet.</p>
    @else
        <ol class="timeline">
            @foreach ($timeline as $entry)
                <li @class(['incoming' => $entry['incoming'], 'automatic' => $entry['automatic']])>
                    <p class="timeline-when">{{ $entry['when'] }}</p>
                    <p class="timeline-what">{{ $entry['what'] }}</p>
                    @if ($entry['body'] !== null)
                        <p class="timeline-body">{{ $entry['body'] }}</p>
                    @endif
                    @if ($entry['link'] !== null)
                        <p class="timeline-body"><a href="{{ $entry['link']['url'] }}">{{ $entry['link']['text'] }}</a>{{ $entry['after'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    <h2>Applications</h2>

    @if ($applicationRows === [])
        <p class="summary">No applications yet.</p>
    @else
        <x-admin-records
            :headers="$applicationHeaders"
            :rows="$applicationRows"
            :summary-columns="ContactController::APPLICATION_SUMMARY_COLUMNS"
            :numeric="ContactController::APPLICATION_NUMBER_COLUMNS"
            link-label="View Application"
        />
    @endif

    {{-- Their form submissions that didn't go through, and how each was followed up --}}
    @if ($formErrorRows !== [])
        <h2>Form Errors</h2>

        <x-admin-records
            :headers="$formErrorHeaders"
            :rows="$formErrorRows"
            :summary-columns="ContactController::FORM_ERROR_SUMMARY_COLUMNS"
            link-label="View Submission"
        />
    @endif

    <a class="back" href="{{ route('admin.contacts') }}">← Back to Contacts</a>
</x-admin-page>
