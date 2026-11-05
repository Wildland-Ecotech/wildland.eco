@use('App\Http\Controllers\Admin\DonationTotalsController')

<x-admin-page title="Totals" current="admin.donations.totals">
    @if (! $hasTotals)
        <p class="summary" role="status">No donations received yet.</p>
    @else
        <p class="summary">Received donations, including ones refunded later, by the date paid in Fort Collins time.</p>

        <div class="filter-actions">
            <a class="download" href="{{ route('admin.donations.totals.csv') }}">Download CSV</a>
        </div>

        @if ($missingFeesNotice)
            <p class="notice">{{ $missingFeesNotice }}</p>
        @endif

        @foreach ($groupings as $grouping)
            <h2>{{ $grouping['title'] }}</h2>

            <x-admin-records
                :headers="$headers"
                :rows="$grouping['rows']"
                :summary-columns="DonationTotalsController::SUMMARY_COLUMNS"
                :numeric="DonationTotalsController::NUMBER_COLUMNS"
            />
        @endforeach
    @endif
</x-admin-page>
