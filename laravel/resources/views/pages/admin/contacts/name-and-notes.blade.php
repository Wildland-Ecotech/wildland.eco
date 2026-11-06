@use('App\Http\Requests\SaveContactRequest')

{{-- The name and notes fields of the contact forms, filled from $contact when editing --}}
<div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="{{ old('name', $contact?->name) }}" required maxlength="255" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>

    @error('name')
        <p class="field-error" id="name-error">{{ $message }}</p>
    @enderror
</div>

<div class="field">
    <label for="notes">Notes (optional)</label>
    <textarea id="notes" name="notes" rows="3" maxlength="{{ SaveContactRequest::NOTES_MAX_LENGTH }}" aria-describedby="notes-hint{{ $errors->has('notes') ? ' notes-error' : '' }}" @error('notes') aria-invalid="true" @enderror>{{ old('notes', $contact?->notes) }}</textarea>
    <p class="hint" id="notes-hint">Standing information, such as “prefers texts after 5pm”.</p>

    @error('notes')
        <p class="field-error" id="notes-error">{{ $message }}</p>
    @enderror
</div>
