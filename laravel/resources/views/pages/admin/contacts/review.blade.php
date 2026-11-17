@use('App\Http\Controllers\Admin\ContactController')

<x-admin-page title="Needs Review" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <h2 id="applications">Applications</h2>
        <p class="hint">Applications with a contact match to check.</p>

        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="ContactController::REVIEW_SUMMARY_COLUMNS"
            :numeric="ContactController::REVIEW_NUMBER_COLUMNS"
            link-label="View Application"
        />
    @endif

    @if ($noSiteRows !== [])
        <h2 id="no-site">Drop-offs With No Site</h2>
        <p class="hint">Drop-offs logged before drop-off sites were recorded. Choose the site on each entry's edit page.</p>

        <x-admin-records
            :headers="$noSiteHeaders"
            :rows="$noSiteRows"
            :summary-columns="ContactController::NO_SITE_SUMMARY_COLUMNS"
            :numeric="ContactController::NO_SITE_NUMBER_COLUMNS"
            link-label="Edit Entry"
        />
    @endif

    @if ($unknownRows !== [])
        <h2 id="unknown">Bags to Identify</h2>
        <p class="hint">Bags found at drop-off sites with no known donor. Choose the contact on each entry's edit page.</p>

        <x-admin-records
            :headers="$unknownHeaders"
            :rows="$unknownRows"
            :summary-columns="ContactController::UNKNOWN_SUMMARY_COLUMNS"
            :numeric="ContactController::UNKNOWN_NUMBER_COLUMNS"
            link-label="Edit Entry"
        />
    @endif

    <a class="back" href="{{ route('admin.contacts') }}">← Back to Contacts</a>
</x-admin-page>
