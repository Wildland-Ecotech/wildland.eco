<x-admin-page :title="'Donation ' . $donation->id" current="admin.donations">
    @if ($deletedAt)
        <p class="notice">This donation was deleted on {{ $deletedAt }}, so the donation list and totals leave it out.</p>
    @endif

    @if ($feeMissing)
        <p class="notice">Stripe's fee isn't recorded yet. The nightly Stripe sync records it once Stripe has it.</p>
    @endif

    <dl class="record">
        @foreach ($fields as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    <a class="back" href="{{ route('admin.donations') }}">← Back to Donations</a>
</x-admin-page>
