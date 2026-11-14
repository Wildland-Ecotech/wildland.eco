@use('App\Http\Controllers\Admin\LeafLedgerController')

<x-admin-page title="Leaf Ledger" current="admin.leaves">
    <p class="intro">Pickups and drop-offs logged on contacts’ timelines and by finishing trailer loads. Each season runs July through June, so late deliveries count toward the autumn before.</p>

    {{-- A plain GET form, so the filter is in the URL and works without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.leaves.ledger') }}">
        <div class="filter">
            <label for="season">Season</label>
            <select id="season" name="season">
                @foreach ($seasons as $value => $label)
                    <option value="{{ $value }}" @selected($season === $value)>{{ $label }}</option>
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
            :summary-columns="LeafLedgerController::SUMMARY_COLUMNS"
            :numeric="LeafLedgerController::NUMBER_COLUMNS"
        />
    @endif

    <a class="back" href="{{ route('admin.leaves') }}">← Back to Leaves</a>
</x-admin-page>
