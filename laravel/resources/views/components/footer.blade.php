@props([
    'color' => 'green',
    'heading' => 'Want to make a difference?',
    'title' => 'Help us restore wildlands for future generations.',
    'buttonText' => 'Get In Touch',
    'buttonHref' => 'mailto:contact@wildland.eco',
    'showDonate' => true,
    'showSocials' => true,
])

<div class="footer footer--{{ $color }}">
    <div class="footer-content">
        <h3>{{ $heading }}</h3>
        <h1>{{ $title }}</h1>

        <div class="footer-actions">
            <a class="button hire full" href="{{ $buttonHref }}">{{ $buttonText }}</a>

            @if ($showDonate)
                <a class="button dark" href="{{ route('donate') }}">Donate</a>
            @endif
        </div>

        @if ($showSocials)
            <h3>Follow our progress</h3>

            <div class="social">
                <a href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">Instagram</a>
                <a href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                <a href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/" target="_blank" rel="noopener noreferrer">Facebook</a>
            </div>
        @endif

        <p class="legal">
            Wildland Ecotech is a 501(c)(3) nonprofit organization.<br>
            EIN: 33-3738514
        </p>
    </div>
</div>
