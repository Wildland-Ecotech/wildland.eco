{{--
    Shell for admin pages: the content page backgrounds with a wide card
    holding the "— Admin" label, the page's heading, links to the admin
    pages, and Sign Out. Link only to admin pages that exist. Usage:

    <x-admin-page title="Donations" current="admin.donations">
        ...
    </x-admin-page>

    `current` is the route name of the link to highlight.
--}}
@props(['title', 'current' => null])

@php
    $links = [
        'admin' => 'Dashboard',
        'admin.donations' => 'Donations',
        'admin.donations.totals' => 'Totals',
        'admin.donations.abandoned' => 'Abandoned Checkouts',
        'admin.leaves' => 'Leaf Applications',
        'admin.errors' => 'Error Logs',
    ];
@endphp

<x-layout :title="$title . ' — Admin — Wildland Ecotech'">
    <x-content-page class="admin-page">
        <section class="card wide">
            <h3>— Admin</h3>
            <h1>{{ $title }}</h1>

            <nav class="admin-nav" aria-label="Admin pages">
                <ul>
                    @foreach ($links as $route => $label)
                        <li>
                            <a href="{{ route($route) }}"{!! $current === $route ? ' aria-current="page"' : '' !!}>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="sign-out">Sign Out</button>
                </form>
            </nav>

            <div class="admin-content">
                {{ $slot }}
            </div>
        </section>
    </x-content-page>
</x-layout>
