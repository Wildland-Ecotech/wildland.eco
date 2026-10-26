<x-error-page label="Page Not Found" title="We couldn't find that page">
    <p class="lead">The link may be out of date, or the address may have a typo.</p>

    <p class="detail">
        If you followed a link on our site, email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a> so we can fix it.
    </p>

    <x-slot:actions>
        <a class="button full" href="{{ route('home') }}">Go to the Home Page</a>
        <a class="button dark" href="{{ route('donate') }}">Donate</a>
    </x-slot:actions>
</x-error-page>
