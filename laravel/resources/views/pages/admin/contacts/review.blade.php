@use('App\Http\Controllers\Admin\ContactController')

<x-admin-page title="Needs Review" current="admin.contacts">
    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="ContactController::REVIEW_SUMMARY_COLUMNS"
            :numeric="ContactController::REVIEW_NUMBER_COLUMNS"
            link-label="View Application"
        />
    @endif

    <a class="back" href="{{ route('admin.contacts') }}">← Back to Contacts</a>
</x-admin-page>
