@php
    $admin = auth()->user();
@endphp

<x-layout title="Admin — Wildland Ecotech">
    <x-content-page class="admin-page">
        <section class="card">
            <h3>— Admin</h3>
            <h1>Dashboard</h1>

            <p class="signed-in">
                Signed in as <strong>{{ $admin->name ?: $admin->email }}</strong> ({{ $admin->email }}).
            </p>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button dark">Sign Out</button>
            </form>
        </section>
    </x-content-page>
</x-layout>
