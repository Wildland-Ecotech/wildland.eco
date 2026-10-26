{{-- A form sent from a page that had expired; usually the page was left open for hours --}}
<x-error-page label="Page Expired" title="This page expired">
    <p class="lead">Your form wasn't processed, and nothing was charged.</p>

    <p class="detail">
        For your security, forms stop working when a page has been open for a long time. Go back to the
        form, reload the page, and submit it again. If it happens again, email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
    </p>

    <x-slot:actions>
        <a class="button full" href="{{ url()->previous(route('home')) }}">Back to the Form</a>
        <a class="button dark" href="{{ route('home') }}">Go to the Home Page</a>
    </x-slot:actions>
</x-error-page>
