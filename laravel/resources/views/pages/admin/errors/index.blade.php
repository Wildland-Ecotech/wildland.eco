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

        <fieldset class="filter">
            <legend>Include</legend>

            {{-- Checked unless turned off: an unchecked box sends its hidden 0, and a checked one's 1 replaces it --}}
            <input type="hidden" name="later" value="0">
            <label class="checkbox">
                <input type="checkbox" name="later" value="1" @checked($filters['later'])>
                <span>Submitted later</span>
            </label>

            <input type="hidden" name="resolved" value="0">
            <label class="checkbox">
                <input type="checkbox" name="resolved" value="1" @checked($filters['resolved'])>
                <span>Resolved by an admin</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="spam" value="1" @checked($filters['spam'])>
                <span>Spam and tests</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="all" value="1" @checked($filters['all'])>
                <span>Submissions that succeeded</span>
            </label>
        </fieldset>

        <div class="filter-actions">
            <button type="submit" class="button dark small">Show</button>
            <a class="download" href="{{ $csvUrl }}">Download CSV</a>
        </div>
    </form>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="ErrorLogController::SUMMARY_COLUMNS"
            link-label="View Submission"
            select-form="close-errors"
            select-name="references[]"
        />

        {{-- The list's checkboxes belong to this form through their form attribute --}}
        <form id="close-errors" class="bulk-actions" method="POST" action="{{ route('admin.errors.close') }}">
            @csrf

            @foreach (['references', 'references.*', 'resolution'] as $field)
                @error($field)
                    <p class="field-error">{{ $message }}</p>
                @enderror
            @endforeach

            <label for="resolution">Mark selected as</label>
            <select id="resolution" name="resolution">
                @foreach (ErrorLogController::RESOLUTION_MENU as $value => $label)
                    <option value="{{ $value }}" @selected(old('resolution') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="button dark small">Mark</button>
        </form>
    @endif
</x-admin-page>
