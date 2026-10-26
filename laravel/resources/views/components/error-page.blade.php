{{--
    Shell for the error pages in resources/views/errors: the content page
    backgrounds with a card holding a label, a heading, what happened and what
    to do (the slot), and buttons (the actions slot). Every error page says
    what went wrong and what to do next; see "Error messages" in
    docs/08-style-guide.md.

    <x-error-page label="Page Not Found" title="We couldn't find that page">
        <p class="lead">...</p>
        <x-slot:actions>...</x-slot:actions>
    </x-error-page>
--}}
@props(['label', 'title'])

<x-layout :title="$title . ' — Wildland Ecotech'">
    <x-content-page class="error-page">
        <div class="error-card">
            <h3>— {{ $label }}</h3>
            <h1>{{ $title }}</h1>

            {{ $slot }}

            @isset($actions)
                <div class="buttons">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    </x-content-page>
</x-layout>
