{{--
    Shell for admin pages: the content page backgrounds with a wide card
    holding Sign Out in its corner, the "— Admin" label, the page's heading,
    links to the admin pages, and, on every page, a red warning while people
    are waiting to hear from us or have bags ready (App\Reports\FollowUpNotice).
    Link only to admin pages that exist. Usage:

    <x-admin-page title="Donations" current="admin.donations">
        ...
    </x-admin-page>

    `current` is the route name of the link to highlight.
--}}
@props(['title', 'current' => null])

@inject('followUpNotice', 'App\Reports\FollowUpNotice')

@php
    $followUpWarning = $followUpNotice->text();
@endphp

@php
    $links = [
        'admin' => 'Dashboard',
        'admin.contacts' => 'Contacts',
        'admin.leaves' => 'Leaf Applications',
        'admin.donations' => 'Donations',
        'admin.errors' => 'Error Logs',
    ];
@endphp

<x-layout :title="$title . ' — Admin — Wildland Ecotech'">
    <x-content-page class="admin-page">
        <section class="card wide">
            <form class="sign-out" method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button dark small">Sign Out</button>
            </form>

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
            </nav>

            <div class="admin-content">
                {{-- People waiting to hear from us, or with bags ready; Follow Up opens Contacts on Needs follow-up --}}
                @if ($followUpWarning)
                    <div class="notice warning" role="status">
                        <p>{{ $followUpWarning }}</p>
                        <a class="button dark small" href="{{ route('admin.contacts') }}">Follow Up</a>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </section>
    </x-content-page>
</x-layout>
