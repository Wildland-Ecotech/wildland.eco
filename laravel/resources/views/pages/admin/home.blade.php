<x-admin-page title="Dashboard" current="admin">
    <p class="signed-in">
        Signed in as <strong>{{ $admin->name ?: $admin->email }}</strong> ({{ $admin->email }}).
    </p>

    <h2>Last {{ $days }} Days</h2>

    <ul class="overview">
        @foreach ($overview as $item)
            <li>
                <a href="{{ $item['url'] }}">
                    <span class="count">{{ number_format($item['count']) }}</span>
                    <span class="label">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</x-admin-page>
