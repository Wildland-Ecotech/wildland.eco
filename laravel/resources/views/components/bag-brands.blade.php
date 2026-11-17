@props(['brands'])

{{-- Earlier bag brands, most used first, suggested by brand fields with list="bag-brands"; any other brand can be typed too --}}
<datalist id="bag-brands">
    @foreach ($brands as $brand)
        <option value="{{ $brand }}"></option>
    @endforeach
</datalist>
