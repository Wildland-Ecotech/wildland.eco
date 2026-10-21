@use('App\Models\LeafApplication')

@php
    $type = old('type', LeafApplication::TYPE_DROP_OFF);
    $readyNow = old('ready_now');
@endphp

<x-layout title="Apply to Donate Leaves — Wildland Ecotech">
    <x-content-page class="leaves-apply-page">
        <section class="apply-card" id="apply">
            <h3>— Leaf Program</h3>
            <h1>Donate Your Leaves</h1>

            @if (session('applied'))
                <div class="thanks" role="status">
                    <h2>Thank you!</h2>

                    <p>
                        We've received your application and will review it soon.
                        @if (session('applied') === LeafApplication::TYPE_PICKUP)
                            Once it's approved, we'll contact you to schedule your pickup.
                        @else
                            Once it's approved, we'll contact you with the details for your drop-off location.
                        @endif
                    </p>

                    <a class="button full" href="{{ route('leaves') }}">Back to the Leaf Program</a>
                </div>
            @else
                <p class="subtitle">
                    Tell us about your leaves, and we'll be in touch once we've reviewed your application.
                </p>

                <form class="apply-form" method="POST" action="{{ route('leaves.apply.store') }}" novalidate>
                    @csrf

                    @if ($errors->any())
                        <div class="error-summary" role="alert">
                            <p>Please fix the following and try again:</p>

                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Hidden from people; spam bots fill in every field. See StoreLeafApplicationRequest. --}}
                    <div class="website-field" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <fieldset class="field choices">
                        <legend>How would you like to give us your leaves?</legend>

                        <label class="choice" for="type-drop-off">
                            <input type="radio" id="type-drop-off" name="type" value="{{ LeafApplication::TYPE_DROP_OFF }}" @checked($type === LeafApplication::TYPE_DROP_OFF)>
                            <span class="choice-text">
                                <strong>Drop-off</strong> <span class="badge">Preferred</span><br>
                                Bring your bags to one of our two Fort Collins locations whenever it's convenient.
                            </span>
                        </label>

                        <label class="choice" for="type-pickup">
                            <input type="radio" id="type-pickup" name="type" value="{{ LeafApplication::TYPE_PICKUP }}" @checked($type === LeafApplication::TYPE_PICKUP)>
                            <span class="choice-text">
                                <strong>Pickup</strong><br>
                                For people without a vehicle that can carry bags of leaves, or with too many bags.
                                We schedule pickups around our volunteers and routes, and we'll contact you to arrange it.
                            </span>
                        </label>

                        @error('type')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <div class="field">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required @error('name') aria-invalid="true" @enderror>
                        @error('name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" required @error('email') aria-invalid="true" @enderror>
                        @error('email')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="phone">Phone number</label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel" required @error('phone') aria-invalid="true" @enderror>
                        @error('phone')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="address">Address the leaves are coming from</label>
                        <p class="hint" id="address-hint">Street address, city, and ZIP code</p>
                        <input type="text" id="address" name="address" value="{{ old('address') }}" autocomplete="street-address" aria-describedby="address-hint" required @error('address') aria-invalid="true" @enderror>
                        @error('address')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="estimated_bag_count">About how many bags?</label>
                        <p class="hint" id="bags-hint">An estimate is fine.</p>
                        <input type="text" id="estimated_bag_count" name="estimated_bag_count" value="{{ old('estimated_bag_count') }}" inputmode="numeric" aria-describedby="bags-hint" required @error('estimated_bag_count') aria-invalid="true" @enderror>
                        @error('estimated_bag_count')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- The date only matters when the bags are not ready yet; CSS hides it while "Yes" is checked --}}
                    <fieldset class="field choices ready">
                        <legend>Are your bags ready now?</legend>

                        <label class="choice" for="ready-now">
                            <input type="radio" id="ready-now" name="ready_now" value="1" @checked($readyNow === '1')>
                            <span class="choice-text">Yes, they're ready now</span>
                        </label>

                        <label class="choice" for="ready-later">
                            <input type="radio" id="ready-later" name="ready_now" value="0" @checked($readyNow === '0')>
                            <span class="choice-text">Not yet</span>
                        </label>

                        @error('ready_now')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        <div class="ready-date">
                            <label for="ready_date">If not, when will they be ready?</label>
                            <p class="hint" id="ready-date-hint">The soonest date your bags will be raked and bagged.</p>
                            <input type="date" id="ready_date" name="ready_date" value="{{ old('ready_date') }}" min="{{ $earliestReadyDate }}" aria-describedby="ready-date-hint" @error('ready_date') aria-invalid="true" @enderror>
                            @error('ready_date')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </fieldset>

                    <div class="field">
                        <label for="referral_source">How did you hear about Wildland Ecotech and our leaf program? <span class="optional">(optional)</span></label>
                        <textarea id="referral_source" name="referral_source" rows="2" @error('referral_source') aria-invalid="true" @enderror>{{ old('referral_source') }}</textarea>
                        @error('referral_source')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="questions">Do you have any questions? <span class="optional">(optional)</span></label>
                        <textarea id="questions" name="questions" rows="3" @error('questions') aria-invalid="true" @enderror>{{ old('questions') }}</textarea>
                        @error('questions')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="terms">
                        <h2>Terms</h2>

                        <p>By applying, you agree that:</p>

                        <ul>
                            <li>
                                We may share the general area your leaves came from, when we received them,
                                how many bags you gave us, and photos of the bags, as described in our
                                <a href="{{ route('leaves') }}#privacy">privacy section</a>.
                            </li>

                            <li>
                                Every bag you give us is a paper compostable lawn bag.
                                We do not accept any lawn material in plastic bags.
                            </li>

                            <li>
                                Every time you give us leaves, you'll check that your bags contain only compostable yard waste,
                                such as leaves, small twigs, and lawn clippings, and no plastic, metal, chemicals,
                                or other non-compostable material.
                            </li>

                            <li>
                                If we find any non-compostable material in your bags, we will no longer accept leaves
                                from your household, because everything we receive is spread across restoration sites.
                            </li>
                        </ul>

                        <label class="checkbox" for="terms">
                            <input type="checkbox" id="terms" name="terms" value="1" @checked(old('terms')) required>
                            <span>Yes, I agree to these terms</span>
                        </label>

                        @error('terms')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="submit-button">Send Application</button>
                </form>
            @endif
        </section>

        <x-slot:footer>
            {{-- Donate stays hidden until donations are enabled with the Stripe integration (milestone 6) --}}
            <x-footer
                heading="Questions about leaves?"
                title="Help us turn yard waste into restored habitat."
                :show-donate="false"
            />
        </x-slot:footer>
    </x-content-page>
</x-layout>
