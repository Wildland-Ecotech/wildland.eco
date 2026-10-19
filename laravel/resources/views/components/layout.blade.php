@props([
    'title' => 'Wildland Ecotech — Restoring Landscapes, Building Abundance',
])

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="{{ asset('favicon.png') }}">

        <meta property="og:title" content="Wildland Ecotech — Restoring Landscapes, Building Abundance">
        <meta property="og:description" content="A 501(c)(3) nonprofit restoring land scarred by wildfire and pollution through ecology, technology, and community.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://wildland.eco/">

        <title>{{ $title }}</title>

        @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    </head>
    <body>
        <x-top-nav />

        {{ $slot }}
    </body>
</html>
