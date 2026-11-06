<x-admin-page :title="'Application ' . $application->id" current="admin.leaves">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if ($deletedAt)
        <p class="notice">This application was deleted on {{ $deletedAt }}, so the application list leaves it out.</p>
    @endif

    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    <h2>Contact</h2>

    @if ($contact === null)
        <p class="summary">Not linked to a contact.</p>
    @elseif ($contact->trashed())
        <p class="summary">Linked to {{ $contact->name }}, a deleted contact.</p>
    @else
        <p class="summary">Linked to <a href="{{ route('admin.contacts.show', $contact->id) }}">{{ $contact->name }}</a>.</p>
    @endif

    {{-- Each reason, escaped by the controller, with links to the other contacts it's about; then what to do --}}
    @if ($reviewReasons !== [])
        <p class="notice">Needs review. @foreach ($reviewReasons as $reason){!! $reason !!} @endforeach{{ $contact ? 'Mark it reviewed if nothing needs to change, or move it to the right contact below.' : 'Link it to the right contact below, or to a new one.' }}</p>
    @endif

    @if ($reviewedNote)
        <p class="summary">{{ $reviewedNote }}</p>
    @endif

    @unless ($deletedAt)
        <div class="review-actions">
            @if ($reviewReasons !== [] && $contact !== null)
                <form method="POST" action="{{ route('admin.leaves.review', $application->id) }}">
                    @csrf
                    <button type="submit" class="button dark small">Mark Reviewed</button>
                </form>
            @endif

            <form class="record-form" method="POST" action="{{ route('admin.leaves.contact', $application->id) }}">
                @csrf

                <label for="contact">{{ $contact ? 'Move this application to' : 'Link this application to' }}</label>

                <div class="field-with-button">
                    <select id="contact" name="contact" @error('contact') aria-invalid="true" aria-describedby="contact-error" @enderror>
                        @foreach ($contactOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(old('contact') === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="button dark small">{{ $contact ? 'Move' : 'Link' }}</button>
                </div>

                @error('contact')
                    <p class="field-error" id="contact-error">{{ $message }}</p>
                @enderror
            </form>
        </div>
    @endunless

    <a class="back" href="{{ route('admin.leaves') }}">← Back to Leaf Applications</a>
</x-admin-page>
