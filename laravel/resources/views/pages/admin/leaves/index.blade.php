@use('App\Http\Controllers\Admin\LeafApplicationController')

<x-admin-page title="Leaf Applications" current="admin.leaves">
    {{-- A plain GET form, so the filters are in the URL and work without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.leaves') }}">
        <div class="filter">
            <label for="period">Period</label>
            <select id="period" name="period">
                @foreach (LeafApplicationController::PERIOD_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <fieldset class="filter">
            <legend>Type</legend>

            @foreach (LeafApplicationController::TYPE_LABELS as $value => $label)
                <label class="checkbox">
                    <input type="checkbox" name="type[]" value="{{ $value }}" @checked(in_array($value, $filters['types'], true))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            <p class="hint">Leave both unchecked to show every application.</p>
        </fieldset>

        <fieldset class="filter">
            <legend>Include</legend>

            <label class="checkbox">
                <input type="checkbox" name="spam" value="1" @checked($filters['spam'])>
                <span>Spam and tests</span>
            </label>
        </fieldset>

        <div class="page-actions">
            <a class="button dark small" href="{{ route('admin.leaves.ledger') }}">Leaf Ledger</a>
        </div>

        <div class="filter-actions">
            <button type="submit" class="button dark small">Show</button>
            <a class="download" href="{{ $csvUrl }}">Download CSV</a>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($bags)
        <p class="summary">{{ $bags }}</p>
    @endif

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="LeafApplicationController::SUMMARY_COLUMNS"
            :numeric="LeafApplicationController::NUMBER_COLUMNS"
            link-label="View Application"
        />
    @endif
</x-admin-page>
