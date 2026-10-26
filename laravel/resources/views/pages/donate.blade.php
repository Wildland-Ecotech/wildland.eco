@use('App\Http\Controllers\DonationController')

@php
    // Suggested amounts in dollars for each giving frequency. The key is
    // used in element IDs; `value` is what the form submits.
    $frequencies = [
        'one-time' => ['value' => 'one_time', 'label' => 'One-Time', 'period' => '', 'suffix' => '', 'tiers' => [5, 20, 50]],
        'monthly' => ['value' => 'monthly', 'label' => 'Monthly', 'period' => '/mo', 'suffix' => '/month', 'tiers' => [20, 50, 100]],
        'annual' => ['value' => 'annual', 'label' => 'Annual', 'period' => '/yr', 'suffix' => '/year', 'tiers' => [100, 1000, 5000]],
    ];

    // No frequency is chosen until the visitor picks one; the rest of the
    // form appears once they do. After a validation error, their choice is kept.
    $selectedFrequency = old('frequency');
@endphp

<x-layout title="Donate — Wildland Ecotech">
    <x-content-page class="donate-page">
        <div class="donate-section">
            <h3>— Support Our Mission</h3>
            <h1>Donate</h1>

            <p class="subtitle">
                Your tax-deductible donation protects communities from wildfire, drought,
                and flooding while restoring habitat for future generations.
            </p>

            {{-- Payment happens on the gateway's page; see DonationController --}}
            <form class="donate-form" method="POST" action="{{ route('donate.store') }}" novalidate data-donate-form @if ($paymentsAvailable) data-proof-of-work @else data-unavailable @endif>
                @csrf

                {{-- Spam protection solved in the browser; see resources/js/proof-of-work.js --}}
                <input type="hidden" name="pow_challenge" value="{{ $proofOfWorkChallenge }}" data-proof-of-work-challenge>
                <input type="hidden" name="pow_nonce" value="" data-proof-of-work-nonce>

                @if ($paymentsAvailable)
                    <noscript>
                        <p class="notice">
                            Donating online needs JavaScript, for our spam protection and our payment processor.
                            Please turn it on, or email us at <a href="mailto:contact@wildland.eco">contact@wildland.eco</a> to give another way.
                        </p>
                    </noscript>
                @else
                    <p class="notice" role="status">{{ DonationController::UNAVAILABLE_MESSAGE }}</p>
                @endif

                @if ($errors->any())
                    <div class="error-summary" role="alert">
                        <p>Please fix the following and try again:</p>

                        <ul>
                            @foreach ($errors->unique() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- The frequency radios must come before the toggle and the details; see resources/scss/pages/_donate.scss --}}
                @foreach ($frequencies as $key => $frequency)
                    <input type="radio" class="frequency-radio" name="frequency" id="frequency-{{ $key }}" value="{{ $frequency['value'] }}" data-frequency data-suffix="{{ $frequency['suffix'] }}" @checked($frequency['value'] === $selectedFrequency)>
                @endforeach

                <div class="frequency-toggle">
                    @foreach ($frequencies as $key => $frequency)
                        <label class="freq-btn" for="frequency-{{ $key }}">{{ $frequency['label'] }}</label>
                    @endforeach
                </div>

                {{-- Hidden until a frequency is chosen, with CSS only --}}
                <div class="donate-details">
                    @foreach ($frequencies as $key => $frequency)
                        <div class="tier-grid tier-grid--{{ $key }}">
                            @foreach ($frequency['tiers'] as $amount)
                                <input type="radio" class="tier-radio" name="amount_{{ $frequency['value'] }}" id="tier-{{ $key }}-{{ $amount }}" value="{{ $amount }}" data-tier @checked(old('amount_' . $frequency['value']) === (string) $amount)>
                                <label class="tier-card" for="tier-{{ $key }}-{{ $amount }}">
                                    <div class="amount">${{ number_format($amount) }}</div>
                                    <div class="period">{{ $frequency['period'] }}</div>
                                </label>
                            @endforeach
                        </div>
                    @endforeach

                    <div class="custom-amount">
                        <label for="custom-amount">Or enter a custom amount</label>

                        <div class="input-wrapper">
                            <span class="dollar-sign">$</span>
                            <input type="text" id="custom-amount" name="custom_amount" value="{{ old('custom_amount') }}" inputmode="decimal" placeholder="Other amount" autocomplete="off" data-custom-amount @error('amount') aria-invalid="true" @enderror>
                        </div>

                        @error('amount')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="donor-fields">
                        <div class="field">
                            <label for="name">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required @error('name') aria-invalid="true" @enderror>
                            @error('name')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="email">Email</label>
                            <p class="hint" id="email-hint">For your receipt, and to manage recurring donations.</p>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" aria-describedby="email-hint" required @error('email') aria-invalid="true" @enderror>
                            @error('email')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <button type="submit" class="donate-button" data-donate-button data-proof-of-work-button @disabled(! $paymentsAvailable)>Donate</button>
                </div>
            </form>

            <p class="tax-note">
                Wildland Ecotech is a 501(c)(3) nonprofit. All donations are tax-deductible. EIN: 33-3738514
            </p>
        </div>

        <div class="section-divider"></div>

        <div class="story">
            <h3>— What You're Supporting</h3>
            <h1>Our Work</h1>

            <p class="story-intro">
                Your donations fund hands-on ecological restoration across 1,000+ acres of
                wildfire-damaged and polluted land across the United States.
            </p>

            <div class="story-grid">
                <div class="story-card">
                    <div class="story-icon">🔥</div>
                    <h2>Wildfire Protection</h2>
                    <p>
                        We reduce fuel loads in burn scars, build water catchment structures that serve
                        as natural firebreaks, and restore wetlands that firefighters use as staging areas
                        during active wildfires.
                    </p>
                </div>

                <div class="story-card">
                    <div class="story-icon">💧</div>
                    <h2>Water Conservation</h2>
                    <p>
                        Our Beaver Dam Analogs slow water down in mountain streams, recharging aquifers
                        and reducing flash flood risk and sediment pollution downstream where communities
                        depend on clean water.
                    </p>
                </div>

                <div class="story-card">
                    <div class="story-icon">🍂</div>
                    <h2>Community Action</h2>
                    <p>
                        We collect over 1,000 bags of leaves each autumn from local neighborhoods,
                        turning waste into compost for habitat restoration. Volunteers of all ages and
                        skill levels power our work.
                    </p>
                </div>

                <div class="story-card">
                    <div class="story-icon">📚</div>
                    <h2>Education</h2>
                    <p>
                        Through hands-on workshops with CSU's STEM 4 Kids program, we teach the next
                        generation about water conservation, wetland ecology, and how beavers protect
                        landscapes from wildfire.
                    </p>
                </div>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="impact">
            <h3>— Our Impact</h3>
            <h1>By The Numbers</h1>

            <div class="impact-grid">
                <div class="impact-stat">
                    <div class="number">1,000+</div>
                    <div class="label">Acres Managed</div>
                </div>

                <div class="impact-stat">
                    <div class="number">70+</div>
                    <div class="label">Beaver Dam Analogs Built</div>
                </div>

                <div class="impact-stat">
                    <div class="number">500+</div>
                    <div class="label">Native Trees &amp; Shrubs Planted</div>
                </div>

                <div class="impact-stat">
                    <div class="number">80+</div>
                    <div class="label">Volunteers</div>
                </div>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="other-ways">
            <h3>— Beyond Donations</h3>
            <h1>Other Ways to Help</h1>

            <p>
                Not everyone can give financially, and that's okay.
                Follow our work, share our story, or volunteer at a restoration event.
            </p>

            <div class="social-buttons">
                <a class="button dark" href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
                    Instagram
                </a>

                <a class="button dark" href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="3" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="8" y1="11" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="8" x2="8" y2="8.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><path d="M12 17v-3.5c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    LinkedIn
                </a>

                <a class="button dark" href="mailto:volunteer@wildland.eco">
                    Volunteer With Us
                </a>
            </div>
        </div>
        <x-slot:footer>
            <x-footer
                heading="Thank you for your support"
                title="Together we can restore wildlands for future generations."
                button-text="Back to Home"
                :button-href="route('home')"
                :show-donate="false"
            />
        </x-slot:footer>
    </x-content-page>
</x-layout>
