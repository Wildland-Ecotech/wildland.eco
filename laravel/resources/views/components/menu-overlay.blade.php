{{-- Opened by the menu button when JavaScript is available; see resources/js/menu.js --}}
<section class="menu-overlay" id="menu-overlay" data-menu-overlay hidden>
    {{-- Repeats the top nav layout, with the wordmark invisible, so the close button sits exactly where the menu button is --}}
    <div class="top-nav">
        <span class="wordmark" aria-hidden="true">Wildland <span class="eco">Ecotech</span></span>

        <x-menu-button />
    </div>

    <ul class="links">
        <li>
            <a class="button full" href="{{ route('home') }}" data-menu-close>Home</a>
        </li>

        <li>
            <a class="button" href="{{ route('leaves') }}" data-menu-close>Leaf Collection</a>
        </li>

        <li>
            <a class="button" href="{{ route('faq') }}" data-menu-close>FAQ</a>
        </li>

        <li>
            <a class="button" href="mailto:contact@wildland.eco" data-menu-close>Contact Us</a>
        </li>
    </ul>
</section>
