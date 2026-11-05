@use('App\Http\Controllers\Admin\AbandonedCheckoutController')

<x-admin-page title="Abandoned Checkouts" current="admin.donations.abandoned">
    <p class="intro">Donors who filled in the donate form and went to Stripe's payment page, but left without paying.</p>

    {{-- A plain GET form, so the filter is in the URL and works without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.donations.abandoned') }}">
        <div class="filter">
            <label for="period">Period</label>
            <select id="period" name="period">
                @foreach (AbandonedCheckoutController::PERIOD_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($period === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="button dark small">Show</button>
            <a class="download" href="{{ $csvUrl }}">Download CSV</a>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="AbandonedCheckoutController::SUMMARY_COLUMNS"
            :numeric="AbandonedCheckoutController::NUMBER_COLUMNS"
        />
    @endif
</x-admin-page>
