@use('App\Http\Controllers\Admin\LeafApplicationController')
@use('App\Http\Controllers\Admin\LeavesController')

<x-admin-page title="Leaves" current="admin.leaves">
    <h2>{{ $seasonName }}</h2>

    <p class="summary">The season so far, since {{ $seasonStart }}.</p>

    <ul class="overview">
        @foreach ($overview as $item)
            <li>
                <a href="{{ $item['url'] }}">
                    <span class="count">{{ number_format($item['count']) }}</span>
                    <span class="label">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <h2>Latest Ledger Entries</h2>

    @if ($ledgerRows === [])
        <p class="summary" role="status">No pickups or drop-offs logged in {{ $seasonName }}.</p>
    @else
        <x-admin-records
            :headers="$ledgerHeaders"
            :rows="$ledgerRows"
            :summary-columns="LeavesController::LEDGER_SUMMARY_COLUMNS"
            :numeric="LeavesController::LEDGER_NUMBER_COLUMNS"
        />
    @endif

    <div class="page-actions">
        <a class="button dark small" href="{{ route('admin.leaves.ledger') }}">Leaf Ledger</a>
    </div>

    <h2>Latest Applications</h2>

    @if ($applicationRows === [])
        <p class="summary" role="status">No applications yet.</p>
    @else
        <x-admin-records
            :headers="$applicationHeaders"
            :rows="$applicationRows"
            :summary-columns="LeafApplicationController::SUMMARY_COLUMNS"
            :numeric="LeafApplicationController::NUMBER_COLUMNS"
            link-label="View Application"
        />
    @endif

    <div class="page-actions">
        <a class="button dark small" href="{{ route('admin.leaves.applications') }}">Leaf Applications</a>
    </div>
</x-admin-page>
