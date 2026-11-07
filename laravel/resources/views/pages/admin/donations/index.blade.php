@use('App\Http\Controllers\Admin\DonationController')

<x-admin-page title="Donations" current="admin.donations">
    {{-- A plain GET form, so the filters are in the URL and work without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.donations') }}">
        <div class="filter-group">
            <div class="filter">
                <label for="period">Period</label>
                <select id="period" name="period">
                    @foreach (DonationController::PERIOD_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['period'] === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <fieldset class="filter">
                <legend>Type</legend>

                @foreach (DonationController::TYPE_LABELS as $value => $label)
                    <label class="checkbox">
                        <input type="checkbox" name="type[]" value="{{ $value }}" @checked(in_array($value, $filters['types'], true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach

                <p class="hint">Leave all unchecked to show every type.</p>
            </fieldset>

            <fieldset class="filter">
                <legend>Include</legend>

                <label class="checkbox">
                    <input type="checkbox" name="all" value="1" @checked($filters['all'])>
                    <span>Pending and failed payments</span>
                </label>
            </fieldset>
        </div>

        <div class="page-actions">
            <a class="button dark small" href="{{ route('admin.donations.abandoned') }}">Abandoned Checkouts</a>
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
            :summary-columns="DonationController::SUMMARY_COLUMNS"
            :numeric="DonationController::NUMBER_COLUMNS"
            link-label="View Donation"
        />
    @endif
</x-admin-page>
