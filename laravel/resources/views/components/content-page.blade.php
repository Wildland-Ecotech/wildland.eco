{{--
    Shell for standalone content pages: the mountain and firefly backgrounds,
    a centered content column that fades out under the top nav, and the
    page's footer. Usage:

    <x-content-page class="example-page">
        ...sections...

        <x-slot:footer>
            <x-footer ... />
        </x-slot:footer>
    </x-content-page>
--}}
<div {{ $attributes->class(['content-page']) }}>
    <div class="backgrounds">
        <div class="mountains"></div>
        <div class="gradient"></div>
        <div class="fireflies"></div>
    </div>

    <div class="page-content">
        <x-disappearing-content>
            {{ $slot }}
        </x-disappearing-content>
    </div>

    {{ $footer ?? '' }}
</div>
