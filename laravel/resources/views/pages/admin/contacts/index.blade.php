@use('App\Http\Controllers\Admin\ContactController')

<x-admin-page title="Contacts" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    {{-- A plain GET form, so a search is in the URL and works without JavaScript --}}
    <form class="filters" method="GET" action="{{ route('admin.contacts') }}">
        <div class="filter-group">
            <div class="filter">
                <label for="show">Show</label>
                <select id="show" name="show">
                    @foreach (ContactController::SHOW_CHOICES as $value => $label)
                        <option value="{{ $value }}" @selected($show === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter">
                <label for="search">Search</label>

                <div class="search-box">
                    <input type="search" id="search" name="q" value="{{ $search }}" maxlength="{{ ContactController::MAX_SEARCH_LENGTH }}" aria-describedby="search-hint">
                    <button type="submit" class="button dark small">Search</button>
                </div>

                <p class="hint" id="search-hint">A name, phone number, or email.</p>
            </div>
        </div>

        <div class="page-actions">
            @if ($reviewCount > 0)
                <a class="button dark small" href="{{ route('admin.contacts.review') }}">Needs Review ({{ number_format($reviewCount) }})</a>
            @endif
            <a class="button dark small" href="{{ route('admin.contacts.create') }}">New Contact</a>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    @if ($rows !== [])
        <x-admin-records
            :headers="$headers"
            :rows="$rows"
            :summary-columns="$summaryColumns"
            :numeric="$numeric"
            link-label="View Contact"
        />
    @endif
</x-admin-page>
