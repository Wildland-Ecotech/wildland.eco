<x-admin-page :title="'Submission ' . $submission->reference" current="admin.errors">
    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    <a class="back" href="{{ route('admin.errors') }}">← Back to Error Logs</a>
</x-admin-page>
