@use('App\Http\Requests\LogContactActivityRequest')

<x-admin-page title="Log Unknown Bags" current="admin.leaves">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <p class="intro">Bags found at drop-off sites with no known donor, listed on the Leaf Ledger as Unknown until assigned to a contact. Log each brand separately, since most donors use one brand.</p>

    @if ($sites === [])
        <p class="hint">No drop-off sites yet. Drop-off sites are contacts with the Drop-off site box checked on the edit page.</p>
    @else
        <form class="record-form" method="POST" action="{{ route('admin.leaves.ledger.unknown.store') }}">
            @csrf

            <div class="field">
                <label for="happened_at">When</label>
                <input type="datetime-local" id="happened_at" name="happened_at" value="{{ old('happened_at', $now) }}" required @error('happened_at') aria-invalid="true" aria-describedby="happened_at-error" @enderror>

                @error('happened_at')
                    <p class="field-error" id="happened_at-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="drop_off_site_id">Drop-off site</label>
                <select id="drop_off_site_id" name="drop_off_site_id" required @error('drop_off_site_id') aria-invalid="true" aria-describedby="drop_off_site_id-error" @enderror>
                    @if (count($sites) > 1)
                        <option value="">Choose a site</option>
                    @endif
                    @foreach ($sites as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('drop_off_site_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>

                @error('drop_off_site_id')
                    <p class="field-error" id="drop_off_site_id-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="bag_count">Bags</label>
                <input type="number" id="bag_count" name="bag_count" min="1" max="{{ LogContactActivityRequest::MAX_BAGS }}" value="{{ $errors->any() ? old('bag_count') : '' }}" required @error('bag_count') aria-invalid="true" aria-describedby="bag_count-error" @enderror>

                @error('bag_count')
                    <p class="field-error" id="bag_count-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="bag_brand">Brand of bags (optional)</label>
                <input type="text" id="bag_brand" name="bag_brand" list="bag-brands" maxlength="{{ LogContactActivityRequest::BRAND_MAX_LENGTH }}" autocomplete="off" value="{{ $errors->any() ? old('bag_brand') : '' }}" @error('bag_brand') aria-invalid="true" aria-describedby="bag_brand-error" @enderror>
                <x-bag-brands :brands="$brands" />

                @error('bag_brand')
                    <p class="field-error" id="bag_brand-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="body">Notes (optional)</label>
                <textarea id="body" name="body" rows="4" maxlength="{{ LogContactActivityRequest::BODY_MAX_LENGTH }}" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ $errors->any() ? old('body') : '' }}</textarea>

                @error('body')
                    <p class="field-error" id="body-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="button dark small">Save</button>
                <button type="submit" class="button dark small" name="add_another" value="1">Save and Add Another</button>
                <a class="back" href="{{ route('admin.leaves.ledger') }}">Cancel</a>
            </div>
        </form>
    @endif
</x-admin-page>
