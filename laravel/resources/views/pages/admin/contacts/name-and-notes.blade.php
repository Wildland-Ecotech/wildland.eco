@use('App\Http\Requests\SaveContactRequest')

{{-- The name, notes, and drop-off site fields of the contact forms, filled from $contact when editing --}}
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

<fieldset class="field">
    <legend>Drop-off site</legend>

    {{-- Unchecked sends 0, so a form sent back with errors keeps it unchecked --}}
    <input type="hidden" name="is_drop_off_site" value="0">
    <label class="checkbox">
        <input type="checkbox" name="is_drop_off_site" value="1" aria-describedby="is_drop_off_site-hint" @checked((bool) old('is_drop_off_site', $contact?->is_drop_off_site))>
        <span>This is one of our drop-off sites</span>
    </label>
    <p class="hint" id="is_drop_off_site-hint">Shown on the map with the applications waiting for a pickup. Its address stays private.</p>

    @error('is_drop_off_site')
        <p class="field-error">{{ $message }}</p>
    @enderror
</fieldset>
