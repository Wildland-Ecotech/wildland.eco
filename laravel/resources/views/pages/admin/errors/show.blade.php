@use('App\Http\Controllers\Admin\ErrorLogController')
@use('App\Models\FormSubmission')

<x-admin-page :title="'Submission ' . $submission->reference" current="admin.errors">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    {{-- The follow-up is an icon and words, linking to what the visitor's later submission made; see the admin-records component --}}
    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>@if (is_array($value))<span class="status-cell"><span class="status-icon {{ $value['status'] }}" aria-hidden="true">{{ ['success' => '✓', 'failure' => '✕', 'muted' => '–'][$value['status']] }}</span>@if ($value['url'] ?? null)<a href="{{ $value['url'] }}">{{ $value['text'] }}</a>@else{{ $value['text'] }}@endif</span>@else{{ $value === '' ? '—' : $value }}@endif</dd>
            </div>
        @endforeach
    </dl>

    @if ($closedNote)
        <form class="close-form" method="POST" action="{{ route('admin.errors.reopen') }}">
            @csrf
            <input type="hidden" name="references[]" value="{{ $submission->reference }}">

            <p>{{ $closedNote }}</p>

            <button type="submit" class="button dark small">Reopen</button>
        </form>
    @else
        <form class="close-form" method="POST" action="{{ route('admin.errors.close') }}">
            @csrf
            <input type="hidden" name="references[]" value="{{ $submission->reference }}">

            <fieldset>
                <legend>Close this error</legend>

                @foreach (ErrorLogController::RESOLUTION_CHOICES as $value => $label)
                    <label class="checkbox">
                        <input type="radio" name="resolution" value="{{ $value }}" @checked(old('resolution', FormSubmission::RESOLUTION_RESOLVED) === $value)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach

                @error('resolution')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="field">
                <label for="note">Note (optional)</label>
                <textarea id="note" name="note" rows="3" maxlength="{{ ErrorLogController::NOTE_MAX_LENGTH }}" @error('note') aria-invalid="true" aria-describedby="note-error" @enderror>{{ old('note') }}</textarea>

                @error('note')
                    <p class="field-error" id="note-error">{{ $message }}</p>
                @enderror
            </div>

            <p>Closed errors aren't counted on the dashboard.</p>

            <button type="submit" class="button dark small">Close Error</button>
        </form>
    @endif

    <a class="back" href="{{ route('admin.errors') }}">← Back to Error Logs</a>
</x-admin-page>
