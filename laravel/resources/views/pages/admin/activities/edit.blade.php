@use('App\Http\Controllers\Admin\ContactActivityController')
@use('App\Http\Requests\LogContactActivityRequest')
@use('App\Models\ContactActivity')

<x-admin-page title="Edit Entry" :current="$activity->contact_id !== null ? 'admin.contacts' : 'admin.leaves'">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="summary">{{ $logged }}.</p>

    {{-- Fields that don't apply to the kind chosen are hidden with :has() in _admin.scss; the server ignores them --}}
    <form class="record-form log-form" method="POST" action="{{ route('admin.activities.update', $activity->id) }}">
        @csrf
        <input type="hidden" name="return_to" value="{{ old('return_to', $returnTo) }}">

        <div class="field">
            <label for="contact_id">Contact</label>
            <select id="contact_id" name="contact_id" data-contact-select aria-describedby="contact_id-hint{{ $errors->has('contact_id') ? ' contact_id-error' : '' }}" @error('contact_id') aria-invalid="true" @enderror>
                <option value="" @selected((string) old('contact_id', $activity->contact_id) === '')>Unknown</option>
                @foreach ($contacts as $id => $name)
                    <option value="{{ $id }}" @selected((string) old('contact_id', $activity->contact_id) === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
            <p class="hint" id="contact_id-hint">Unknown is for drop-offs with no known donor.</p>

            @error('contact_id')
                <p class="field-error" id="contact_id-error">{{ $message }}</p>
            @enderror
        </div>

        <fieldset>
            <legend>What happened</legend>

            @foreach (ContactActivityController::TYPE_LABELS as $value => $label)
                <label class="checkbox">
                    <input type="radio" name="type" value="{{ $value }}" @checked(old('type', $activity->type) === $value)>
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
                    <input type="radio" name="direction" value="{{ $value }}" @checked(old('direction', $activity->direction ?? ContactActivity::DIRECTION_OUTGOING) === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            @error('direction')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="field">
            <label for="happened_at">When</label>
            <input type="datetime-local" id="happened_at" name="happened_at" value="{{ old('happened_at', $happenedAt) }}" required @error('happened_at') aria-invalid="true" aria-describedby="happened_at-error" @enderror>

            @error('happened_at')
                <p class="field-error" id="happened_at-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="body">What was said, or the note (optional)</label>
            <textarea id="body" name="body" rows="4" maxlength="{{ LogContactActivityRequest::BODY_MAX_LENGTH }}" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ old('body', $activity->body) }}</textarea>

            @error('body')
                <p class="field-error" id="body-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field bags-field">
            <label for="bag_count">Bags</label>
            <input type="number" id="bag_count" name="bag_count" min="1" max="{{ LogContactActivityRequest::MAX_BAGS }}" value="{{ old('bag_count', $activity->bag_count) }}" @error('bag_count') aria-invalid="true" aria-describedby="bag_count-error" @enderror>

            @error('bag_count')
                <p class="field-error" id="bag_count-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field bags-field">
            <label for="bag_brand">Brand of bags (optional)</label>
            <input type="text" id="bag_brand" name="bag_brand" list="bag-brands" maxlength="{{ LogContactActivityRequest::BRAND_MAX_LENGTH }}" autocomplete="off" value="{{ old('bag_brand', $activity->bag_brand) }}" @error('bag_brand') aria-invalid="true" aria-describedby="bag_brand-error" @enderror>
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
                    @if (count($sites) > 1 || $activity->drop_off_site_id === null)
                        <option value="">Choose a site</option>
                    @endif
                    @foreach ($sites as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('drop_off_site_id', $activity->drop_off_site_id) === (string) $id)>{{ $name }}</option>
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
                        <option value="{{ $id }}" @selected((string) old('leaf_application_id', $activity->leaf_application_id) === (string) $id)>{{ $label }}</option>
                    @endforeach
                    <option value="" @selected((string) old('leaf_application_id', $activity->leaf_application_id) === '')>None</option>
                </select>
                <p class="hint" id="leaf_application_id-hint">A pickup or drop-off marks it complete. An application left with no pickup or drop-off goes back to pending.</p>
                <p class="hint" data-contact-changed-hint hidden>Applications of the new contact can be chosen after saving.</p>

                @error('leaf_application_id')
                    <p class="field-error" id="leaf_application_id-error">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="form-actions">
            <button type="submit" class="button dark small">Save</button>
            <a class="back" href="{{ $cancelUrl }}">Cancel</a>
        </div>
    </form>

    <h2 id="history">History</h2>

    @if ($history === [])
        <p class="summary">No edits yet.</p>
    @else
        <ol class="timeline">
            @foreach ($history as $edit)
                <li>
                    <p class="timeline-when">{{ $edit['when'] }}</p>
                    <p class="timeline-what">{{ $edit['action'] }} by {{ $edit['who'] }}</p>
                    @foreach ($edit['changes'] as $change)
                        <p class="timeline-body">{{ $change }}</p>
                    @endforeach
                </li>
            @endforeach
        </ol>
    @endif

    <h2 id="delete">Delete Entry</h2>

    <form class="record-form" method="POST" action="{{ route('admin.activities.destroy', $activity->id) }}">
        @csrf
        <input type="hidden" name="return_to" value="{{ old('return_to', $returnTo) }}">

        <p class="hint">Deleted entries leave the timeline and the Leaf Ledger. The deletion is kept in the history.</p>

        <label class="checkbox">
            <input type="checkbox" name="confirm" value="1" @error('confirm') aria-invalid="true" aria-describedby="confirm-error" @enderror>
            <span>Delete this entry</span>
        </label>

        @error('confirm')
            <p class="field-error" id="confirm-error">{{ $message }}</p>
        @enderror

        <div class="form-actions">
            <button type="submit" class="button dark small">Delete Entry</button>
        </div>
    </form>
</x-admin-page>
