{{-- close-href: where the close link points without JavaScript, usually the section that opened the modal --}}
@props([
    'id',
    'title',
    'closeHref' => '#',
])

{{--
    Opened by links to #{{ $id }}. Without JavaScript, CSS shows the modal while its id is the URL fragment (:target).
    With JavaScript, resources/js/modal.js opens and closes it without changing the URL, and adds Escape and click-outside to close.
--}}
<div class="modal-backdrop" id="{{ $id }}" role="dialog" aria-modal="true" aria-label="{{ $title }}" data-modal>
    <div class="modal-wrapper">
        <a class="close-button" href="{{ $closeHref }}" aria-label="Close" data-modal-close>✕</a>

        {{ $slot }}
    </div>
</div>
