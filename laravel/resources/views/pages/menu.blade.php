<x-layout title="Menu — Wildland Ecotech">
    <div class="menu-page">
        <ul class="links">
            <li>
                <a class="button full" href="{{ route('home') }}">Home</a>
            </li>

            <li>
                <a class="button" href="{{ route('leaves') }}">Leaf Collection</a>
            </li>

            <li>
                <a class="button" href="{{ route('faq') }}">FAQ</a>
            </li>

            <li>
                <a class="button" href="mailto:contact@wildland.eco">Contact Us</a>
            </li>
        </ul>

        <a class="back" href="{{ route('home') }}">← Back to site</a>
    </div>
</x-layout>
