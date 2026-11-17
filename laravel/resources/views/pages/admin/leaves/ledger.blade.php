@use('App\Http\Controllers\Admin\LeafLedgerController')

<x-admin-page title="Leaf Ledger" current="admin.leaves">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="intro">Pickups and drop-offs logged on contacts' timelines, by finishing trailer loads, and as unknown bags left at drop-off sites. Each season runs July through June, so late deliveries count toward the autumn before.</p>

    <div class="record-actions">
        <a class="button dark small" href="{{ route('admin.leaves.ledger.unknown.create') }}">Log Unknown Bags</a>
    </div>

    {{-- A plain GET form, so the filters are in the URL and work without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.leaves.ledger') }}">
        <div class="filter">
            <label for="season">Season</label>
            <select id="season" name="season">
                @foreach ($seasons as $value => $label)
                    <option value="{{ $value }}" @selected($season === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <fieldset class="filter">
            <legend>Show</legend>

            <label class="checkbox">
                <input type="checkbox" name="unknown" value="1" @checked($unknownOnly)>
                <span>Unknown only</span>
            </label>
        </fieldset>

        <div class="filter-actions">
            <button type="submit" class="button dark small">Show</button>
            <a class="download" href="{{ $csvUrl }}">Download CSV</a>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($noneShown !== null)
        <p class="hint">{{ $noneShown }}</p>
    @endif

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
