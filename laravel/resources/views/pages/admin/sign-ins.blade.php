@use('App\Http\Controllers\Admin\SignInController')

<x-admin-page title="Sign-Ins" current="admin.errors">
    <p class="intro">
        Every attempt to sign in to the admin pages, so we can spot someone trying to get in.
        Passwords are never recorded, and attempts are kept indefinitely.
    </p>

    {{-- Failed attempts in the last day, also shown on the dashboard and Error Logs --}}
    @if ($notice)
        <div class="notice warning" role="status">
            <p>{{ $notice }}</p>
        </div>
    @endif

    {{-- A plain GET form, so the filters are in the URL and work without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.sign-ins') }}">
        <div class="filter">
            <label for="show">Show</label>
            <select id="show" name="show">
                @foreach (SignInController::SHOW_CHOICES as $value => $label)
                    <option value="{{ $value }}" @selected($show === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="filter">
            <label for="search">Search</label>

            <div class="search-box">
                <input type="search" id="search" name="q" value="{{ $search }}" maxlength="{{ SignInController::MAX_SEARCH_LENGTH }}" aria-describedby="search-hint">
                <button type="submit" class="button dark small">Search</button>
            </div>

            <p class="hint" id="search-hint">An email or IP address.</p>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="SignInController::SUMMARY_COLUMNS"
        />
    @endif

    <a class="back" href="{{ route('admin.errors') }}">← Back to Error Logs</a>
</x-admin-page>
