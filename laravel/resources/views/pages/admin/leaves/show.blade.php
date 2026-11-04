<x-admin-page :title="'Application ' . $application->id" current="admin.leaves">
    @if ($deletedAt)
        <p class="notice">This application was deleted on {{ $deletedAt }}, so the application list leaves it out.</p>
    @endif

    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    <a class="back" href="{{ route('admin.leaves') }}">← Back to Leaf Applications</a>
</x-admin-page>
