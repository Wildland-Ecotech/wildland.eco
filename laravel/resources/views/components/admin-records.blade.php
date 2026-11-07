{{--
    A list of records on an admin page: a table on wider screens, where each
    row's first column (such as its ID) links to the record, and on phones
    one line per record that opens to show the other columns and a button to
    the record. Both are plain HTML, so
    they work without JavaScript (<details>). Usage:

    <x-admin-records
        :headers="['ID', 'Date', 'Donor', ...]"
        :rows="$rows"
        :summary-columns="[1, 2, 4]"
        :numeric="[0, 4]"
        link-label="View Donation"
        select-form="mark-spam"
        select-name="references[]"
    />

    Each row is ['cells' => one value per header, 'summary' => the words of
    the phone line, 'url' => the record's page], with an optional 'badge':
    a short label after the first column's link and the phone line, such as
    "Drop-off site", and its 'badgeTone': `urgent` (red), `soon` (yellow),
    or `muted` (gray), or green without one. Leave out 'url' for records
    without a page of their own, such as totals; the list then has no links.
    The first column should identify the record, since it is the link.
    `summary-columns` are the columns the phone line already shows, which the
    opened line leaves out. `numeric` columns line up on the right in the
    table. Empty values show as a dash.

    A cell can also be ['text' => ..., 'status' => ..., 'url' => ...]: the
    text after a status icon, linking to 'url' when it isn't null. The
    statuses are `success` (a check), `failure` (a cross), and `muted` (a
    dash), always beside words, since color alone doesn't carry meaning.
    Without 'status', it's the text alone, linking to 'url', such as the
    contact an application belongs to. A cell can also be ['badge' => ...,
    'tone' => ..., 'text' => ...]: a label, with any tone a row's badge
    takes, then the text, such as an application's follow-up. A
    row's 'status' and 'statusLabel' start its phone line with the same icon,
    with the label read out in its place.

    With `select-form`, each record gets a checkbox named `select-name`,
    holding the row's 'value', for the form with that ID elsewhere on the
    page, such as one with a button for the chosen records.
--}}
@props(['headers', 'rows', 'summaryColumns' => [], 'numeric' => [], 'linkLabel' => 'View', 'selectForm' => null, 'selectName' => null])

@php
    $icons = ['success' => '✓', 'failure' => '✕', 'muted' => '–'];
    // A label, escaped: green, or `urgent` (red), `soon` (yellow), or `muted` (gray)
    $badge = fn (string $text, ?string $tone = null) => '<span class="' . e(trim('badge ' . $tone)) . '">' . e($text) . '</span>';
@endphp

<div class="admin-records">
    <table class="records-table">
        <thead>
            <tr>
                @if ($selectForm)
                    <th scope="col" class="select"><span class="visually-hidden">Select</span></th>
                @endif
                @foreach ($headers as $index => $header)
                    <th scope="col" @class(['numeric' => in_array($index, $numeric, true)])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @if ($selectForm)
                        <td class="select"><input type="checkbox" form="{{ $selectForm }}" name="{{ $selectName }}" value="{{ $row['value'] }}" aria-label="Select {{ $row['value'] }}"></td>
                    @endif
                    @foreach ($row['cells'] as $index => $cell)
                        <td @class(['numeric' => in_array($index, $numeric, true)])>@if ($index === 0 && isset($row['url']))<a class="record-link" href="{{ $row['url'] }}">{{ $cell }}</a>{!! isset($row['badge']) ? ' ' . $badge($row['badge'], $row['badgeTone'] ?? null) : '' !!}@elseif (is_array($cell) && isset($cell['badge'])){!! $badge($cell['badge'], $cell['tone'] ?? null) !!}{{ $cell['text'] !== '' ? ' ' . $cell['text'] : '' }}@elseif (is_array($cell) && ! isset($cell['status']) && ($cell['url'] ?? null))<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@elseif (is_array($cell) && ! isset($cell['status'])){{ $cell['text'] }}@elseif (is_array($cell))<span class="status-cell"><span class="status-icon {{ $cell['status'] }}" aria-hidden="true">{{ $icons[$cell['status']] }}</span>@if ($cell['url'] ?? null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif</span>@else{{ $cell === '' ? '—' : $cell }}@endif</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <ul class="records-list">
        @foreach ($rows as $row)
            <li{!! $selectForm ? ' class="selectable"' : '' !!}>
                @if ($selectForm)
                    <span class="select"><input type="checkbox" form="{{ $selectForm }}" name="{{ $selectName }}" value="{{ $row['value'] }}" aria-label="Select {{ $row['value'] }}"></span>
                @endif

                <details>
                    <summary>@if ($row['status'] ?? null)<span class="status-icon {{ $row['status'] }}" aria-hidden="true">{{ $icons[$row['status']] }}</span><span class="visually-hidden">{{ $row['statusLabel'] }}: </span>@endif{{ implode(' · ', $row['summary']) }}{!! isset($row['badge']) ? ' ' . $badge($row['badge'], $row['badgeTone'] ?? null) : '' !!}</summary>

                    <dl>
                        @foreach ($row['cells'] as $index => $cell)
                            @continue(in_array($index, $summaryColumns, true))
                            <div>
                                <dt>{{ $headers[$index] }}</dt>
                                <dd>@if (is_array($cell) && isset($cell['badge'])){!! $badge($cell['badge'], $cell['tone'] ?? null) !!}{{ $cell['text'] !== '' ? ' ' . $cell['text'] : '' }}@elseif (is_array($cell) && ! isset($cell['status']) && ($cell['url'] ?? null))<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@elseif (is_array($cell) && ! isset($cell['status'])){{ $cell['text'] }}@elseif (is_array($cell))<span class="status-cell"><span class="status-icon {{ $cell['status'] }}" aria-hidden="true">{{ $icons[$cell['status']] }}</span>@if ($cell['url'] ?? null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif</span>@else{{ $cell === '' ? '—' : $cell }}@endif</dd>
                            </div>
                        @endforeach
                    </dl>

                    @isset($row['url'])
                        <a class="button dark small" href="{{ $row['url'] }}">{{ $linkLabel }}</a>
                    @endisset
                </details>
            </li>
        @endforeach
    </ul>
</div>
