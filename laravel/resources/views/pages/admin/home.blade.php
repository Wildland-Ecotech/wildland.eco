@php
    $admin = auth()->user();
@endphp

<x-admin-page title="Dashboard" current="admin">
    <p class="signed-in">
        Signed in as <strong>{{ $admin->name ?: $admin->email }}</strong> ({{ $admin->email }}).
    </p>
</x-admin-page>
