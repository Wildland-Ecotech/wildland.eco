@use('App\Http\Controllers\Admin\MapController')
@use('App\Http\Requests\SaveLocationsRequest')

<x-admin-page title="Map" current="admin.map">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    {{-- A plain GET form, so the view and a search are in the URL and work without JavaScript --}}
    <form class="filters no-print" method="GET" action="{{ route('admin.map') }}">
        <div class="filter-group">
            <div class="filter">
                <label for="show">Show</label>
                <select id="show" name="show">
                    @foreach (MapController::SHOW_CHOICES as $value => $label)
                        <option value="{{ $value }}" @selected($show === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter">
                <label for="search">Search</label>

                <div class="search-box">
                    <input type="search" id="search" name="q" value="{{ $search }}" maxlength="{{ MapController::MAX_SEARCH_LENGTH }}" aria-describedby="search-hint">
                    <button type="submit" class="button dark small">Search</button>
                </div>

                <p class="hint" id="search-hint">A name, phone number, or email, to see where they are and their closest drop-off site.</p>
            </div>
        </div>
    </form>

    <p class="summary" role="status">{{ $summary }}</p>

    {{-- resources/js/admin-map.js draws the map from #map-data; the legend names the dots' colors --}}
    <div class="map-view" data-map role="region" aria-label="Map">
        <noscript><p class="map-fallback">The map needs JavaScript. The list below has everyone on it.</p></noscript>
    </div>
    <script type="application/json" id="map-data">@json($points)</script>

    @if ($legend !== [])
        <ul class="map-legend">
            @foreach ($legend as $kind => $count)
                <li><span class="swatch {{ $kind }}" aria-hidden="true"></span>{{ MapController::KIND_LABELS[$kind] }} ({{ number_format($count) }})</li>
            @endforeach
        </ul>
    @endif

    {{-- Needs pickup: soonest first, with a running total of bags for filling the trailer --}}
    @if ($pickupRows !== [])
        <h2>Pickups</h2>
        <p class="summary">Soonest first, with a running total of bags, for filling the trailer.</p>

        <x-admin-records
            :headers="MapController::PICKUP_HEADERS"
            :rows="$pickupRows"
            :summary-columns="MapController::PICKUP_SUMMARY_COLUMNS"
            :numeric="MapController::PICKUP_NUMBER_COLUMNS"
            link-label="View"
        />
    @endif

    {{-- A search: each contact found, with the drop-off sites nearest first --}}
    @foreach ($results as $result)
        <h2>{{ $result['name'] }}</h2>
        <p class="summary">@if ($result['details'] !== ''){{ $result['details'] }} · @endif<a href="{{ $result['url'] }}">View contact</a></p>

        @if (! $result['located'])
            <p class="summary">No location yet, so the distances aren’t known. Locate their address below.</p>
        @elseif ($result['sites'] !== [])
            <dl class="record">
                @foreach ($result['sites'] as $index => $site)
                    <div>
                        <dt>{{ $index === 0 ? 'Closest drop-off site' : 'Next closest' }}</dt>
                        <dd>{{ $site['name'] }} · {{ $site['distance'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="detail-hint">Straight-line distance; driving may be farther.</p>
        @endif
    @endforeach

    {{-- Copy addresses as JSON for addresses:geocode on an admin's computer, then paste what it printed back --}}
    <section class="no-print">
        <h2>Locate Addresses</h2>

        @if ($unlocatedCount === 0)
            <p class="summary">Every address has a location.</p>
        @else
            <p class="summary">{{ number_format($unlocatedCount) }} {{ Str::plural('address', $unlocatedCount) }} {{ $unlocatedCount === 1 ? 'needs' : 'need' }} a location: drop-off sites first, then those waiting for a pickup.</p>
        @endif

        {{-- Only while an address needs a location, or to show a preview or an error --}}
        @if ($unlocatedCount > 0 || $previewRows !== null || $errors->has('results'))
            <form class="record-form" method="POST" action="{{ route('admin.map.locations') }}">
                @csrf

                @if ($unlocatedCount > 0)
                    {{-- The commands first, so copying the addresses is the last thing before pasting them --}}
                    <div class="field">
                        <p class="field-label">1. Run these in your local setup</p>
                        <code class="command">docker compose --profile geocoder up -d geocoder
docker compose exec app php artisan addresses:geocode</code>
                        <p class="hint">It asks for the addresses. Nothing is saved to a file.</p>
                    </div>

                    <div class="field">
                        <label for="unlocated">2. Copy these addresses, paste them into the terminal, then press Enter</label>
                        <textarea id="unlocated" class="lines-box" rows="{{ min(10, $unlocatedCount + 2) }}" readonly aria-describedby="unlocated-hint">{{ $unlocated }}</textarea>
                        <p class="hint" id="unlocated-hint">Each address with its number, one per line. It starts once everything is pasted, then prints the locations it found.</p>
                    </div>
                @endif

                <div class="field">
                    <label for="results">{{ $unlocatedCount > 0 ? '3. Paste what it printed' : 'Paste what addresses:geocode printed' }}</label>
                    <textarea id="results" name="results" class="lines-box" rows="5" maxlength="{{ SaveLocationsRequest::MAX_LENGTH }}" aria-describedby="results-hint{{ $errors->has('results') ? ' results-error' : '' }}" @error('results') aria-invalid="true" @enderror>{{ $pasted }}</textarea>
                    <p class="hint" id="results-hint">Everything from the { to the }. Addresses it found only at the street, or not at all, are listed in the preview to look up by hand. Nothing is saved until you check the preview.</p>

                    @error('results')
                        <p class="field-error" id="results-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($previewRows !== null)
                    <h3>Preview</h3>
                    <p class="summary">{{ $saveable }} of {{ count($previewRows) }} {{ Str::plural('address', count($previewRows)) }} can be saved. The others are left out.</p>

                    @if ($previewRows !== [])
                        <x-admin-records
                            :headers="MapController::PREVIEW_HEADERS"
                            :rows="$previewRows"
                            :summary-columns="MapController::PREVIEW_SUMMARY_COLUMNS"
                        />
                    @endif
                @endif

                <div class="form-actions">
                    <button type="submit" class="button dark small">Preview</button>
                    @if ($saveable > 0)
                        <button type="submit" class="button dark small" name="confirm" value="1">Save {{ $saveable }} {{ Str::plural('Location', $saveable) }}</button>
                    @endif
                </div>
            </form>
        @endif
    </section>
</x-admin-page>
