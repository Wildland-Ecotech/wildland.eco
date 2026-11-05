@use('App\Http\Controllers\Admin\ErrorLogController')

<x-admin-page title="Error Logs" current="admin.errors">
    <p class="intro">
        Donate and leaf application form submissions that didn't go through, so we can follow up with the people who made them.
        A visitor who emails us may quote the reference shown with their error.
    </p>

    {{-- A plain GET form, so the filters are in the URL and work without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.errors') }}">
        <div class="filter">
            <label for="period">Period</label>
            <select id="period" name="period">
                @foreach (ErrorLogController::PERIOD_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <fieldset class="filter">
            <legend>Form</legend>

            @foreach (ErrorLogController::FORM_LABELS as $value => $label)
                <label class="checkbox">
                    <input type="checkbox" name="form[]" value="{{ $value }}" @checked(in_array($value, $filters['forms'], true))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            <p class="hint">Leave both unchecked to show both forms.</p>
        </fieldset>

        <label class="checkbox">
            <input type="checkbox" name="all" value="1" @checked($filters['all'])>
            <span>Include submissions that succeeded</span>
        </label>

        <div class="filter-actions">
            <button type="submit" class="button dark">Show</button>
            <a class="download" href="{{ $csvUrl }}">Download CSV</a>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="ErrorLogController::SUMMARY_COLUMNS"
            link-label="View Submission"
        />
    @endif
</x-admin-page>
