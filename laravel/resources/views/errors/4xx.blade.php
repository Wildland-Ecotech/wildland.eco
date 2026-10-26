{{-- Any other client error, such as a 403 or 405, without a page of its own --}}
<x-error-page :label="'Error ' . $exception->getStatusCode()" title="We couldn't complete that request">
    <p class="lead">The page or form you used may be out of date, or the link may be wrong.</p>

    <p class="detail">
        Go back and try again. If it keeps happening, email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a> and tell us what you were trying to do.
    </p>

    <x-slot:actions>
        <a class="button full" href="{{ route('home') }}">Go to the Home Page</a>
    </x-slot:actions>
</x-error-page>
