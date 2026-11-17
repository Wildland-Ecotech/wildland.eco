@use('App\Http\Controllers\Admin\ContactActivityController')
@use('App\Http\Requests\LogContactActivityRequest')
@use('App\Models\ContactActivity')

<x-admin-page title="Log Activity" current="admin.contacts">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="summary">{{ $contact->name }}@if ($phone) · {{ $phone }}@endif</p>

    {{-- Fields that don't apply to the kind chosen are hidden with :has() in _admin.scss; the server ignores them --}}
    <form class="record-form log-form" method="POST" action="{{ route('admin.contacts.activities.store', $contact->id) }}">
        @csrf

        <fieldset>
            <legend>What happened</legend>

            @foreach (ContactActivityController::TYPE_LABELS as $value => $label)
                <label class="checkbox">
                    <input type="radio" name="type" value="{{ $value }}" @checked(old('type', ContactActivity::TYPE_TEXT) === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            @error('type')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </fieldset>

        <fieldset class="direction-field">
            <legend>Who reached out</legend>

            @foreach (ContactActivityController::DIRECTION_LABELS as $value => $label)
                <label class="checkbox">
                    <input type="radio" name="direction" value="{{ $value }}" @checked(old('direction', ContactActivity::DIRECTION_OUTGOING) === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            @error('direction')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="field">
            <label for="happened_at">When</label>
            <input type="datetime-local" id="happened_at" name="happened_at" value="{{ $errors->any() ? old('happened_at', $now) : $now }}" required @error('happened_at') aria-invalid="true" aria-describedby="happened_at-error" @enderror>

            @error('happened_at')
                <p class="field-error" id="happened_at-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="body">What was said, or the note (optional)</label>
            <textarea id="body" name="body" rows="4" maxlength="{{ LogContactActivityRequest::BODY_MAX_LENGTH }}" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ $errors->any() ? old('body') : '' }}</textarea>

            @error('body')
                <p class="field-error" id="body-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field bags-field">
            <label for="bag_count">Bags</label>
            <input type="number" id="bag_count" name="bag_count" min="1" max="{{ LogContactActivityRequest::MAX_BAGS }}" value="{{ $errors->any() ? old('bag_count') : '' }}" @error('bag_count') aria-invalid="true" aria-describedby="bag_count-error" @enderror>

            @error('bag_count')
                <p class="field-error" id="bag_count-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field bags-field">
            <label for="bag_brand">Brand of bags (optional)</label>
            <input type="text" id="bag_brand" name="bag_brand" list="bag-brands" maxlength="{{ LogContactActivityRequest::BRAND_MAX_LENGTH }}" autocomplete="off" value="{{ $errors->any() ? old('bag_brand') : '' }}" @error('bag_brand') aria-invalid="true" aria-describedby="bag_brand-error" @enderror>
            <x-bag-brands :brands="$brands" />

            @error('bag_brand')
                <p class="field-error" id="bag_brand-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field site-field">
            <label for="drop_off_site_id">Drop-off site</label>
            @if ($sites === [])
                <p class="hint">No drop-off sites yet. Drop-off sites are contacts with the Drop-off site box checked on the edit page.</p>
            @else
                <select id="drop_off_site_id" name="drop_off_site_id" @error('drop_off_site_id') aria-invalid="true" aria-describedby="drop_off_site_id-error" @enderror>
                    @if (count($sites) > 1)
                        <option value="">Choose a site</option>
                    @endif
                    @foreach ($sites as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('drop_off_site_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            @error('drop_off_site_id')
                <p class="field-error" id="drop_off_site_id-error">{{ $message }}</p>
            @enderror
        </div>

        @if ($applications !== [])
            <div class="field">
                <label for="leaf_application_id">Application</label>
                <select id="leaf_application_id" name="leaf_application_id" aria-describedby="leaf_application_id-hint{{ $errors->has('leaf_application_id') ? ' leaf_application_id-error' : '' }}" @error('leaf_application_id') aria-invalid="true" @enderror>
                    @foreach ($applications as $id => $label)
                        <option value="{{ $id }}" @selected((string) old('leaf_application_id', array_key_first($applications)) === (string) $id)>{{ $label }}</option>
                    @endforeach
                    <option value="" @selected(old('leaf_application_id', array_key_first($applications)) === null)>None</option>
                </select>
                <p class="hint" id="leaf_application_id-hint">A pickup or drop-off marks it complete, taking it off the map and the follow-up list.</p>

                @error('leaf_application_id')
                    <p class="field-error" id="leaf_application_id-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="follow_up_on">Follow up on (optional)</label>
                <input type="date" id="follow_up_on" name="follow_up_on" value="{{ $errors->any() ? old('follow_up_on') : '' }}" min="{{ substr($now, 0, 10) }}" aria-describedby="follow_up_on-hint{{ $errors->has('follow_up_on') ? ' follow_up_on-error' : '' }}" @error('follow_up_on') aria-invalid="true" @enderror>
                <p class="hint" id="follow_up_on-hint">Moves them down the follow-up list until then, such as when they asked us to check back next week.</p>

                @error('follow_up_on')
                    <p class="field-error" id="follow_up_on-error">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="form-actions">
            <button type="submit" class="button dark small">Save</button>
            <button type="submit" class="button dark small" name="add_another" value="1">Save and Add Another</button>
            <a class="back" href="{{ route('admin.contacts.show', $contact->id) }}">Cancel</a>
        </div>
    </form>
</x-admin-page>
