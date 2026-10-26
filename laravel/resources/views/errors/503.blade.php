{{-- Maintenance mode (php artisan down). On servers, Caddy shows its own matching page instead (deploy/caddy/maintenance.html). --}}
<x-error-page label="Site Update" title="We'll be right back">
    <p class="lead">We're updating the site. This usually takes less than a minute.</p>

    <p class="detail">
        Please try again shortly. Questions? Email us at
        <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
    </p>
</x-error-page>
