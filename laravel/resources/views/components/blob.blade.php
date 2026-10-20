{{-- type: shape 1–10 from blob-shapes. When set, the morphing animation never shrinks below this shape; when omitted, a random shape is used. --}}
@props([
    'type' => null,
])

@php
    $shape = $type ?? random_int(1, 10);

    // Two rotating gradient layers behind the image, each clipped to a
    // different random shape and spinning in opposite directions
    $backgroundShapes = collect(range(1, 10))->reject(fn ($n) => $n === $shape)->shuffle()->values();
    $directions = random_int(0, 1) ? ['normal', 'reverse'] : ['reverse', 'normal'];

    $layers = [
        ['shape' => $backgroundShapes[0], 'duration' => random_int(3000, 5000) / 100, 'direction' => $directions[0]],
        ['shape' => $backgroundShapes[1], 'duration' => random_int(3500, 6000) / 100, 'direction' => $directions[1]],
    ];
@endphp

@once
    <x-blob-shapes />
@endonce

<div class="blob-container">
    @foreach ($layers as $index => $layer)
        <div class="blob-bg blob-bg-{{ $index + 1 }} blob-{{ $layer['shape'] }}" style="--spin-duration: {{ $layer['duration'] }}s; --spin-direction: {{ $layer['direction'] }};"></div>
    @endforeach

    <div class="blob-{{ $shape }}" data-blob data-blob-shape="{{ $shape }}" @if ($type) data-blob-floor @endif>
        {{ $slot }}
    </div>
</div>
