<x-admin-page title="New Contact" current="admin.contacts">
    {{-- Another contact already has the phone number or email: nothing was added yet --}}
    @foreach (session('duplicates', []) as $duplicate)
        <p class="notice">{{ $duplicate['value'] }} {{ $duplicate['previous'] ? 'was' : 'is already' }} {{ $duplicate['name'] }}’s {{ $duplicate['kind'] }}. <a href="{{ $duplicate['url'] }}">Open {{ $duplicate['name'] }}</a> to add to that contact, or add a separate contact anyway.</p>
    @endforeach

    <form class="record-form" method="POST" action="{{ route('admin.contacts.store') }}">
        @csrf

        @include('pages.admin.contacts.name-and-notes', ['contact' => null])

        @foreach (['phone' => ['Phone (optional)', 'tel'], 'email' => ['Email (optional)', 'email'], 'address' => ['Address (optional)', 'text']] as $field => [$label, $type])
            <div class="field">
                <label for="{{ $field }}">{{ $label }}</label>
                <input type="{{ $type }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>

                @error($field)
                    <p class="field-error" id="{{ $field }}-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        @if (session('duplicates') || old('separate'))
            <label class="checkbox">
                <input type="checkbox" name="separate" value="1" @checked(old('separate'))>
                <span>Add as a separate contact anyway</span>
            </label>
        @endif

        <div class="form-actions">
            <button type="submit" class="button dark small">Add Contact</button>
            <a class="back" href="{{ route('admin.contacts') }}">Cancel</a>
        </div>
    </form>
</x-admin-page>
