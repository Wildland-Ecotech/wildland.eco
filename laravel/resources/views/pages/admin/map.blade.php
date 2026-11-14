@use('App\Http\Controllers\Admin\MapController')
@use('App\Http\Requests\SaveLocationsRequest')
@use('App\Http\Requests\SaveTrailerLoadRequest')

<x-admin-page :title="$title" current="admin.map">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    {{-- A plain GET form, so the view and a search are in the URL and work without JavaScript --}}
    @if ($load === null)
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

            @if ($show === MapController::SHOW_PICKUPS && $search === '' && ! $focused && $builder === null)
                <div class="page-actions">
                    <a class="button dark small" href="{{ route('admin.map', ['build' => 1]) }}">Start a Trailer Load</a>
                </div>
            @endif
        </form>
    @endif

    {{-- The trailer loads, first on every view but an opened load, so the planned ones are what's seen on arrival --}}
    @if ($load === null)
        <h2>Trailer Loads</h2>

        @if ($plannedLoadRows === [])
            <p class="summary">No trailer loads are planned.</p>
        @else
            <x-admin-records
                :headers="MapController::LOAD_HEADERS"
                :rows="$plannedLoadRows"
                :summary-columns="MapController::LOAD_SUMMARY_COLUMNS"
                :numeric="MapController::LOAD_NUMBER_COLUMNS"
                link-label="Open"
            />
        @endif

        @if ($pastLoadRows !== [])
            <details class="past-loads">
                <summary>Past loads ({{ number_format(count($pastLoadRows)) }})</summary>

                <x-admin-records
                    :headers="MapController::PAST_LOAD_HEADERS"
                    :rows="$pastLoadRows"
                    :summary-columns="MapController::PAST_LOAD_SUMMARY_COLUMNS"
                    :numeric="MapController::PAST_LOAD_NUMBER_COLUMNS"
                    link-label="Open"
                />
            </details>
        @endif
    @endif

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

    {{-- A new or edited trailer load: the list's boxes belong to this form, and resources/js/admin-map.js adds clicking dots and the live progress bar --}}
    @if ($builder !== null)
        <form class="load-builder no-print" id="trailer-load" method="POST" action="{{ $builder['action'] }}">
            @csrf

            <h3>{{ $builder['title'] }}</h3>
            <p class="detail-hint">{{ $builder['hint'] }}</p>

            @if ($errors->has('stops') || $errors->has('stops.*'))
                <p class="field-error" role="alert">{{ $errors->first('stops') ?: $errors->first('stops.*') }}</p>
            @endif

            <div class="load-builder-fields">
                <div class="field">
                    <label for="planned_on">Date</label>
                    <input type="date" id="planned_on" name="planned_on" value="{{ $plannedOn }}" required @error('planned_on') aria-invalid="true" aria-describedby="planned_on-error" @enderror>

                    @error('planned_on')
                        <p class="field-error" id="planned_on-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="capacity">Capacity (bags)</label>
                    <input type="number" id="capacity" name="capacity" value="{{ $capacity }}" min="1" max="{{ SaveTrailerLoadRequest::MAX_CAPACITY }}" required @error('capacity') aria-invalid="true" aria-describedby="capacity-error" @enderror>

                    @error('capacity')
                        <p class="field-error" id="capacity-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="load-bar {{ $progress['tone'] }}" data-load-bar>
                    <div class="load-bar-track"><div class="load-bar-fill" style="--fill: {{ $progress['percent'] }}%"></div></div>
                    <p class="load-bar-label" aria-live="polite">{{ $progress['label'] }}</p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="button dark small">{{ $builder['button'] }}</button>
                <a class="back" href="{{ $builder['cancel'] }}">Cancel</a>
            </div>
        </form>
        <script type="application/json" id="load-bags">@json((object) $loadBags)</script>
        {{-- The stops numbered first: an edited load's in route order, or a form's sent back with errors in the order chosen --}}
        <script type="application/json" id="load-order">@json($loadOrder)</script>
    @endif

    {{-- Needs scheduling: soonest first, with a running total of bags for filling the trailer --}}
    @if ($pickupRows !== [])
        <h2>Pickups</h2>
        <p class="summary">{{ $editing ? 'This load’s stops, and the pickups not in another load, soonest first.' : 'Soonest first, with a running total of bags, for filling the trailer.' }}</p>

        @if ($builder !== null)
            <x-admin-records
                :headers="MapController::PICKUP_HEADERS"
                :rows="$pickupRows"
                :summary-columns="MapController::PICKUP_SUMMARY_COLUMNS"
                :numeric="MapController::PICKUP_NUMBER_COLUMNS"
                link-label="View"
                select-form="trailer-load"
                select-name="stops[]"
            />
        @else
            <x-admin-records
                :headers="MapController::PICKUP_HEADERS"
                :rows="$pickupRows"
                :summary-columns="MapController::PICKUP_SUMMARY_COLUMNS"
                :numeric="MapController::PICKUP_NUMBER_COLUMNS"
                link-label="View"
            />
        @endif
    @endif

    {{-- One trailer load: its stops in route order, to follow on a phone --}}
    @if ($load !== null && ! $editing)
        <div class="load-bar {{ $progress['tone'] }}">
            <div class="load-bar-track"><div class="load-bar-fill" style="--fill: {{ $progress['percent'] }}%"></div></div>
            <p class="load-bar-label">{{ $progress['label'] }}</p>
        </div>

        @if ($canEdit)
            <div class="load-actions">
                <a class="button dark small" href="{{ route('admin.map', ['load' => $load->id, 'edit' => 1]) }}">Edit Load</a>
                <a class="button dark small" href="{{ route('admin.map.loads.done', $load->id) }}">Mark Done</a>
            </div>
        @endif

        <h2 id="stops">Stops</h2>

        @if ($stops === [])
            <p class="summary">This load has no stops.</p>
        @else
            @if ($canEdit)
                <p class="summary">In your route’s order. Move a stop up or down to change it.</p>
            @endif

            {{-- A table on wider screens, and on phones a card for each stop with everything shown --}}
            <div class="load-stops">
                <table class="records-table stop-table">
                    <thead>
                        <tr>
                            <th scope="col">Stop</th>
                            <th scope="col">Name</th>
                            <th scope="col" class="numeric">Bags</th>
                            <th scope="col">Address</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Notes</th>
                            @if ($showPickups)
                                <th scope="col">Pickup</th>
                            @endif
                            @if ($canEdit)
                                <th scope="col"><span class="visually-hidden">Move</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stops as $stop)
                            <tr>
                                <td>{{ $stop['position'] }}</td>
                                <td><a href="{{ $stop['url'] }}">{{ $stop['name'] }}</a></td>
                                <td class="numeric">{{ $stop['bags'] }}</td>
                                <td>{{ $stop['address'] }}</td>
                                <td class="stop-phone">@if ($stop['tel'] !== null)<a href="{{ $stop['tel'] }}">{{ $stop['phone'] }}</a>@else{{ $stop['phone'] ?? '—' }}@endif</td>
                                <td class="stop-notes">{{ $stop['notes'] !== '' ? $stop['notes'] : '—' }}</td>
                                @if ($showPickups)
                                    <td>{{ $stop['pickup'] }}</td>
                                @endif
                                @if ($canEdit)
                                    <td>
                                        <div class="stop-moves">
                                            @unless ($loop->first)
                                                <form method="POST" action="{{ route('admin.map.loads.move', [$load->id, $stop['application'], 'up']) }}">
                                                    @csrf
                                                    <button type="submit" class="button dark small" aria-label="Move {{ $stop['name'] }} up">Up</button>
                                                </form>
                                            @endunless
                                            @unless ($loop->last)
                                                <form method="POST" action="{{ route('admin.map.loads.move', [$load->id, $stop['application'], 'down']) }}">
                                                    @csrf
                                                    <button type="submit" class="button dark small" aria-label="Move {{ $stop['name'] }} down">Down</button>
                                                </form>
                                            @endunless
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <ol class="stop-cards">
                    @foreach ($stops as $stop)
                        <li>
                            <p class="stop-card-name"><span class="stop-number">{{ $stop['position'] }}</span> <a href="{{ $stop['url'] }}">{{ $stop['name'] }}</a> <span class="stop-bags">{{ $stop['bags'] }} {{ Str::plural('bag', $stop['bags']) }}</span></p>
                            <p>{{ $stop['address'] }}</p>
                            @if ($stop['tel'] !== null)
                                <p><a href="{{ $stop['tel'] }}">{{ $stop['phone'] }}</a></p>
                            @elseif ($stop['phone'] !== null)
                                <p>{{ $stop['phone'] }}</p>
                            @endif
                            @if ($stop['notes'] !== '')
                                <p class="stop-notes">{{ $stop['notes'] }}</p>
                            @endif
                            @if ($showPickups)
                                <p class="stop-pickup">{{ $stop['pickup'] }}</p>
                            @endif
                            @if ($canEdit && count($stops) > 1)
                                <div class="stop-moves">
                                    @unless ($loop->first)
                                        <form method="POST" action="{{ route('admin.map.loads.move', [$load->id, $stop['application'], 'up']) }}">
                                            @csrf
                                            <button type="submit" class="button dark small" aria-label="Move {{ $stop['name'] }} up">Up</button>
                                        </form>
                                    @endunless
                                    @unless ($loop->last)
                                        <form method="POST" action="{{ route('admin.map.loads.move', [$load->id, $stop['application'], 'down']) }}">
                                            @csrf
                                            <button type="submit" class="button dark small" aria-label="Move {{ $stop['name'] }} down">Down</button>
                                        </form>
                                    @endunless
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Canceling, at the end, away from Edit Load, with a box to confirm --}}
        @if ($canEdit)
            <h2 id="cancel">Cancel This Load</h2>

            <form class="record-form" method="POST" action="{{ route('admin.map.loads.cancel', $load->id) }}">
                @csrf

                <fieldset class="field">
                    <legend>Confirm</legend>

                    <label class="checkbox">
                        <input type="checkbox" name="confirm" value="1" aria-describedby="confirm-hint{{ $errors->has('confirm') ? ' confirm-error' : '' }}" @error('confirm') aria-invalid="true" @enderror>
                        <span>Cancel the {{ $load->label() }} load</span>
                    </label>
                    <p class="hint" id="confirm-hint">Its stops go back to Needs scheduling. The load is kept, marked Canceled.</p>

                    @error('confirm')
                        <p class="field-error" id="confirm-error">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="button dark small">Cancel Load</button>
                </div>
            </form>
        @endif

        <a class="back" href="{{ route('admin.map') }}">← Back to Map</a>
    @endif

    {{-- A search, or one contact opened from their address: each with the drop-off sites nearest first --}}
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

    {{-- Copy addresses as JSON for addresses:geocode on an admin's computer, then paste what it printed back; only while there's something to locate, or a preview or an error to show --}}
    @if ($load === null && ($unlocatedCount > 0 || $previewRows !== null || $errors->has('results')))
        <section class="no-print">
            <h2>Locate Addresses</h2>

            @if ($unlocatedCount === 0)
                <p class="summary">Every address has a location.</p>
            @else
                <p class="summary">{{ number_format($unlocatedCount) }} {{ Str::plural('address', $unlocatedCount) }} {{ $unlocatedCount === 1 ? 'needs' : 'need' }} a location: drop-off sites first, then those waiting for a pickup.</p>
            @endif

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
        </section>
    @endif
</x-admin-page>
