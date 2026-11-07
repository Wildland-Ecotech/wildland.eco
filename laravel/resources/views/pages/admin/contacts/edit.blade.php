@use('App\Http\Requests\DeleteContactRequest')
@use('App\Models\ContactDetail')

<x-admin-page :title="$contact->name" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <form class="record-form" method="POST" action="{{ route('admin.contacts.update', $contact->id) }}">
        @csrf

        @include('pages.admin.contacts.name-and-notes', ['contact' => $contact])

        <div class="form-actions">
            <button type="submit" class="button dark small">Save</button>
        </div>
    </form>

    {{-- Phone numbers and emails: each with its buttons, then a box to add one --}}
    @foreach ([ContactDetail::TYPE_PHONE => ['Phone Numbers', $phones, 'Add a phone number', 'tel'], ContactDetail::TYPE_EMAIL => ['Emails', $emails, 'Add an email', 'email']] as $type => [$heading, $details, $addLabel, $inputType])
        <h2>{{ $heading }}</h2>

        @if ($details->isNotEmpty())
            <ul class="detail-list">
                @foreach ($details as $detail)
                    <li>
                        <span class="detail-value">{{ $detail->value }}@if ($detail->label) <span class="detail-note">· {{ $detail->label }}</span>@endif @if ($detail->is_primary)<span class="detail-note">· Main</span>@endif</span>

                        @unless ($detail->is_primary)
                            <form method="POST" action="{{ route('admin.contacts.details.primary', [$contact->id, $detail->id]) }}">
                                @csrf
                                <button type="submit" class="button dark small">Make Main</button>
                            </form>
                        @endunless

                        <form method="POST" action="{{ route('admin.contacts.details.remove', [$contact->id, $detail->id]) }}">
                            @csrf
                            <button type="submit" class="button dark small">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <form class="record-form" method="POST" action="{{ route('admin.contacts.details.store', $contact->id) }}">
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">

            <label for="new-{{ $type }}">{{ $addLabel }}</label>

            <div class="field-with-button">
                <input type="{{ $inputType }}" id="new-{{ $type }}" name="{{ $type }}" value="{{ old('type') === $type ? old($type) : '' }}" @error($type) aria-invalid="true" aria-describedby="new-{{ $type }}-error" @enderror>
                <button type="submit" class="button dark small">Add</button>
            </div>

            @error($type)
                <p class="field-error" id="new-{{ $type }}-error">{{ $message }}</p>
            @enderror
        </form>
    @endforeach

    <h2>Address</h2>

    @if ($address)
        <ul class="detail-list">
            <li>
                <span class="detail-value">{{ $address->value }}</span>

                <form method="POST" action="{{ route('admin.contacts.details.remove', [$contact->id, $address->id]) }}">
                    @csrf
                    <button type="submit" class="button dark small">Remove</button>
                </form>
            </li>
        </ul>
    @endif

    <form class="record-form" method="POST" action="{{ route('admin.contacts.details.store', $contact->id) }}">
        @csrf
        <input type="hidden" name="type" value="{{ ContactDetail::TYPE_ADDRESS }}">

        <label for="new-address">{{ $address ? 'New address' : 'Add an address' }}</label>

        <div class="field-with-button">
            <input type="text" id="new-address" name="address" value="{{ old('type') === ContactDetail::TYPE_ADDRESS ? old('address') : '' }}" @if ($address || $errors->has('address')) aria-describedby="{{ trim(($address ? 'new-address-hint' : '') . ($errors->has('address') ? ' new-address-error' : '')) }}" @endif @error('address') aria-invalid="true" @enderror>
            <button type="submit" class="button dark small">{{ $address ? 'Change Address' : 'Add Address' }}</button>
        </div>

        @if ($address)
            <p class="hint" id="new-address-hint">The current address is kept as a previous address.</p>
        @endif

        @error('address')
            <p class="field-error" id="new-address-error">{{ $message }}</p>
        @enderror
    </form>

    {{-- Deleting a contact added by mistake or as a test; one with applications is merged instead --}}
    <h2 id="delete">Delete Contact</h2>

    @if ($applicationCount > 0)
        <p class="detail-hint">{{ DeleteContactRequest::hasApplications($contact, $applicationCount) }}</p>
    @else
        <form class="record-form" method="POST" action="{{ route('admin.contacts.destroy', $contact->id) }}">
            @csrf

            <fieldset class="field">
                <legend>Confirm</legend>

                <label class="checkbox">
                    <input type="checkbox" name="confirm" value="1" aria-describedby="confirm-hint{{ $errors->has('confirm') ? ' confirm-error' : '' }}" @error('confirm') aria-invalid="true" @enderror>
                    <span>Delete {{ $contact->name }}</span>
                </label>
                <p class="hint" id="confirm-hint">For contacts added by mistake or as a test. They disappear from Contacts, the map, and matching new applications, along with their timeline.</p>

                @error('confirm')
                    <p class="field-error" id="confirm-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="button dark small">Delete Contact</button>
            </div>
        </form>
    @endif

    <a class="back" href="{{ route('admin.contacts.show', $contact->id) }}">← Back to {{ $contact->name }}</a>
</x-admin-page>
