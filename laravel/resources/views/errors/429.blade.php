<x-error-page label="Too Many Requests" title="Please wait a moment">
    <p class="lead">We received a lot of requests from your connection in a short time.</p>

    <p class="detail">
        Wait a minute, then try again. If you keep seeing this, email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
    </p>

    <x-slot:actions>
        <a class="button full" href="{{ route('home') }}">Go to the Home Page</a>
    </x-slot:actions>
</x-error-page>
