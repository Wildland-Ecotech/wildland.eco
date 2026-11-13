@use('App\Http\Controllers\Admin\ApplicationFlagController')
@use('App\Http\Requests\FlagLeafApplicationRequest')
@use('App\Models\LeafApplication')

<x-admin-page :title="'Application ' . $application->id" current="admin.leaves">
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if ($flaggedNote)
        <p class="notice">{{ $flaggedNote }} The application list leaves it out unless it includes spam and tests.</p>
    @elseif ($deletedAt)
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

    @if ($flaggedNote)
        <h2 id="mark">Spam or Test</h2>

        <form class="close-form" method="POST" action="{{ route('admin.leaves.unflag', $application->id) }}">
            @csrf

            <p>Unmarking returns the application to every list, along with its contact if the contact was deleted. Error logs closed with it stay closed.</p>

            <button type="submit" class="button dark small">Unmark</button>
        </form>
    @elseif (! $deletedAt)
        <h2 id="mark">Spam or Test</h2>

        <form class="close-form" method="POST" action="{{ route('admin.leaves.flag', $application->id) }}">
            @csrf

            <fieldset>
                <legend>Mark this application as</legend>

                @foreach (ApplicationFlagController::FLAG_CHOICES as $value => $label)
                    <label class="checkbox">
                        <input type="radio" name="flag" value="{{ $value }}" @checked(old('flag', LeafApplication::FLAG_SPAM) === $value)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach

                @error('flag')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="field">
                <label for="note">Note (optional)</label>
                <textarea id="note" name="note" rows="3" maxlength="{{ FlagLeafApplicationRequest::NOTE_MAX_LENGTH }}" @error('note') aria-invalid="true" aria-describedby="note-error" @enderror>{{ old('note') }}</textarea>

                @error('note')
                    <p class="field-error" id="note-error">{{ $message }}</p>
                @enderror
            </div>

            <p>Marked applications leave every list, and their error logs close the same way. Contacts with only marked applications can be deleted.</p>

            <button type="submit" class="button dark small">Mark Application</button>
        </form>
    @endif

    <a class="back" href="{{ route('admin.leaves') }}">← Back to Leaf Applications</a>
</x-admin-page>
