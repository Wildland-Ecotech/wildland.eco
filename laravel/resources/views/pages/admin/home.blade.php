@use('App\Http\Controllers\Admin\DashboardController')
@use('App\Http\Controllers\Admin\SignInController')

<x-admin-page title="Dashboard" current="admin">
    <p class="signed-in">
        Signed in as <strong>{{ $admin->name ?: $admin->email }}</strong> ({{ $admin->email }}).
    </p>

    {{-- People waiting to hear from us, or with bags ready; Follow Up opens Contacts, which lists them first --}}
    @if ($followUpNotice)
        <div class="notice warning" role="status">
            <p>{{ $followUpNotice }}</p>
            <a class="button dark small" href="{{ route('admin.contacts') }}">Follow Up</a>
        </div>
    @endif

    {{-- Failed attempts to sign in in the last day; Review Sign-Ins opens the failed ones --}}
    @if ($signInNotice)
        <div class="notice warning" role="status">
            <p>{{ $signInNotice }}</p>
            <a class="button dark small" href="{{ route('admin.sign-ins', ['show' => SignInController::SHOW_FAILED]) }}">Review Sign-Ins</a>
        </div>
    @endif

    <h2>Recent Activity</h2>

    <p class="summary">The last {{ $days }} days.</p>

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

    <h2>Donation Statistics</h2>

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
            <h3>{{ $grouping['title'] }}</h3>

            <x-admin-records
                :headers="$totalsHeaders"
                :rows="$grouping['rows']"
                :summary-columns="DashboardController::TOTALS_SUMMARY_COLUMNS"
                :numeric="DashboardController::TOTALS_NUMBER_COLUMNS"
            />
        @endforeach
    @endif
</x-admin-page>
