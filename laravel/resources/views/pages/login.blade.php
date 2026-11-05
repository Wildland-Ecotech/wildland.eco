<x-layout title="Sign In — Wildland Ecotech">
    <x-content-page class="admin-page">
        <section class="card">
            <h3>— Account</h3>
            <h1>Sign In</h1>

            @if (session('status'))
                <p class="status" role="status">{{ session('status') }}</p>
            @endif

            <form class="sign-in-form" method="POST" action="{{ route('login.store') }}" novalidate>
                @csrf

                {{-- A wrong password, or too many attempts --}}
                @error('form')
                    <div class="error-summary" role="alert">
                        <p>{{ $message }}</p>
                    </div>
                @enderror

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

                <button type="submit" class="button full small">Sign In</button>

                <p class="help">
                    Forgot your password? Email us at <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.
                </p>
            </form>
        </section>
    </x-content-page>
</x-layout>
