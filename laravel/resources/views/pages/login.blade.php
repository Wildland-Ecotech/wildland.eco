<x-layout title="Sign In — Wildland Ecotech">
    <x-content-page class="admin-page">
        <section class="card">
            <h3>— Account</h3>
            <h1>Sign In</h1>

            @if (session('status'))
                <p class="status" role="status">{{ session('status') }}</p>
            @endif

            <form class="sign-in-form" method="POST" action="{{ route('login.store') }}" novalidate data-proof-of-work data-form-tokens-url="{{ route('form-tokens') }}">
                @csrf

                {{-- Protection against password guessing, solved in the browser; see resources/js/proof-of-work.js --}}
                <input type="hidden" name="pow_challenge" value="{{ $proofOfWorkChallenge }}" data-proof-of-work-challenge>
                <input type="hidden" name="pow_nonce" value="" data-proof-of-work-nonce>

                <noscript>
                    <p class="js-notice">To protect against password guessing, signing in needs JavaScript. Please turn it on, then reload this page.</p>
                </noscript>

                {{-- A wrong password, too many attempts, or a browser that didn't finish the proof of work --}}
                @if ($errors->hasAny(['form', 'pow_challenge', 'pow_nonce']))
                    <div class="error-summary" role="alert">
                        <p>{{ $errors->first('form') ?: ($errors->first('pow_nonce') ?: $errors->first('pow_challenge')) }}</p>
                    </div>
                @endif

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus @error('email') aria-invalid="true" @enderror>
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" @enderror>
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="button full small" data-proof-of-work-button>Sign In</button>

                <p class="help">
                    Forgot your password? Email us at <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
                </p>
            </form>
        </section>
    </x-content-page>
</x-layout>
