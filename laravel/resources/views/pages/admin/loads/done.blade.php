@use('App\Http\Requests\LogContactActivityRequest')

<x-admin-page title="Mark Load Done" current="admin.map">
    <p class="summary">{{ $summary }}</p>

    <form class="record-form done-form" method="POST" action="{{ route('admin.map.loads.finish', $load->id) }}">
        @csrf

        <div class="field">
            <label for="finished_at">When</label>
            <input type="datetime-local" id="finished_at" name="finished_at" value="{{ $finishedAt }}" required @error('finished_at') aria-invalid="true" aria-describedby="finished_at-error" @enderror>

            @error('finished_at')
                <p class="field-error" id="finished_at-error">{{ $message }}</p>
            @enderror
        </div>

        <fieldset class="field">
            <legend>Pickups</legend>

            @foreach (['stops', 'stops.*', 'bags'] as $field)
                @error($field)
                    <p class="field-error">{{ $message }}</p>
                @enderror
            @endforeach

            @if ($stops === [])
                <p class="hint">No stops are left to log.</p>
            @else
                {{-- Shown on phones too: a box, the stop, and its bags fit side by side --}}
                <table class="records-table done-table">
                    <thead>
                        <tr>
                            <th scope="col" class="select"><span class="visually-hidden">Picked up</span></th>
                            <th scope="col">Stop</th>
                            <th scope="col">Bags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stops as $stop)
                            <tr>
                                <td class="select">
                                    @if ($stop['loggable'])
                                        <input type="checkbox" id="stop-{{ $stop['application'] }}" name="stops[]" value="{{ $stop['application'] }}"{!! $stop['checked'] ? ' checked' : '' !!}>
                                    @endif
                                </td>
                                <td>
                                    @if ($stop['loggable'])
                                        <label class="done-stop" for="stop-{{ $stop['application'] }}">{{ $stop['position'] }}. {{ $stop['name'] }}</label>
                                    @else
                                        <span class="done-stop">{{ $stop['position'] }}. {{ $stop['name'] }}</span>
                                    @endif
                                    <span class="done-address">{{ $stop['address'] }}</span>
                                    @unless ($stop['loggable'])
                                        <span class="done-address">Not linked to a contact, so its pickup can’t be logged here. <a href="{{ $stop['url'] }}">Link it</a>, then log the pickup on the contact’s page. Until then it stays pending.</span>
                                    @endunless
                                </td>
                                <td>
                                    @if ($stop['loggable'])
                                        @php($field = "bags.{$stop['application']}")
                                        <input type="number" class="done-bags" name="bags[{{ $stop['application'] }}]" value="{{ $stop['bags'] }}" min="1" max="{{ LogContactActivityRequest::MAX_BAGS }}" aria-label="Bags from {{ $stop['name'] }}" aria-describedby="bags-{{ $stop['application'] }}-hint{{ $errors->has($field) ? " bags-{$stop['application']}-error" : '' }}" @error($field) aria-invalid="true" @enderror>
                                        <span class="hint" id="bags-{{ $stop['application'] }}-hint">estimated {{ number_format($stop['estimate']) }}</span>

                                        @error($field)
                                            <p class="field-error" id="bags-{{ $stop['application'] }}-error">{{ $message }}</p>
                                        @enderror
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <p class="hint">
                Uncheck a stop that wasn’t picked up; it stays pending, ready for another load.
                Each checked stop is logged as a pickup on its contact’s timeline, which marks the application complete.
                @if ($skipped > 0)
                    {{ $skipped === 1 ? '1 other stop was' : "{$skipped} other stops were" }} already picked up or deleted, so {{ $skipped === 1 ? 'it isn’t' : 'they aren’t' }} listed.
                @endif
            </p>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="button dark small">Mark Done</button>
            <a class="back" href="{{ route('admin.map', ['load' => $load->id]) }}">Cancel</a>
        </div>
    </form>
</x-admin-page>
