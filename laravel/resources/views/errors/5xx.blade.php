{{-- Any server error other than maintenance mode (503), shown when APP_DEBUG is false. The error itself is in the Laravel log. --}}
<x-error-page label="Server Error" title="Something went wrong on our end">
    <p class="lead">The problem has been logged, and it isn't anything you did.</p>

    <p class="detail">
        Please try again in a few minutes. If you were making a donation, check your email for a receipt
        before trying again, so you aren't charged twice. Questions? Email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
    </p>

    <x-slot:actions>
        <a class="button full" href="{{ route('home') }}">Go to the Home Page</a>
    </x-slot:actions>
</x-error-page>
