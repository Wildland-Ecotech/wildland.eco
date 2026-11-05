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

    @if ($spamNote)
        <form class="spam-form" method="POST" action="{{ route('admin.errors.unmark-spam') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="references[]" value="{{ $submission->reference }}">

            <p>{{ $spamNote }}</p>

            <button type="submit" class="button dark">Not Spam</button>
        </form>
    @else
        <form class="spam-form" method="POST" action="{{ route('admin.errors.spam') }}">
            @csrf
            <input type="hidden" name="references[]" value="{{ $submission->reference }}">

            <p>Spam is hidden from Error Logs and isn't counted on the dashboard.</p>

            <button type="submit" class="button dark">Mark as Spam</button>
        </form>
    @endif

    <a class="back" href="{{ route('admin.errors') }}">← Back to Error Logs</a>
</x-admin-page>
