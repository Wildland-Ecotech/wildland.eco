{{-- Without JavaScript this navigates to /menu. With JavaScript it toggles the menu overlay. --}}
<a href="{{ route('menu') }}" class="menu-button" aria-label="Menu" aria-controls="menu-overlay" aria-expanded="false" data-menu-toggle>
    {{-- Drawn as an icon rather than the ≡ character, whose position varies between fonts --}}
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
</a>
