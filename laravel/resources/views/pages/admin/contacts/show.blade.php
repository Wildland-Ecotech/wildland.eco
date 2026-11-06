@use('App\Http\Controllers\Admin\ContactController')

<x-admin-page :title="$contact->name" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="record-actions">
        <a class="button dark small" href="{{ route('admin.contacts.edit', $contact->id) }}">Edit Contact</a>
    </div>

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

    <a class="back" href="{{ route('admin.contacts') }}">← Back to Contacts</a>
</x-admin-page>
