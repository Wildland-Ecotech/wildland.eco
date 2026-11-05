{{--
    A list of records on an admin page: a table on wider screens, with a View
    link in each row, and on phones one line per record that opens to show
    the other columns and a button to the record. Both are plain HTML, so
    they work without JavaScript (<details>). Usage:

    <x-admin-records
        :headers="['ID', 'Date', 'Donor', ...]"
        :rows="$rows"
        :summary-columns="[1, 2, 4]"
        :numeric="[0, 4]"
        link-label="View Donation"
    />

    Each row is ['cells' => one value per header, 'summary' => the words of
    the phone line, 'url' => the record's page]. Leave out 'url' for records
    without a page of their own, such as totals; the list then has no links.
    `summary-columns` are the columns the phone line already shows, which the
    opened line leaves out. `numeric` columns line up on the right in the
    table. Empty values show as a dash.
--}}
@props(['headers', 'rows', 'summaryColumns' => [], 'numeric' => [], 'linkLabel' => 'View'])

@php
    $linked = collect($rows)->contains(fn (array $row) => isset($row['url']));
@endphp

<div class="admin-records">
    <table class="records-table">
        <thead>
            <tr>
                @foreach ($headers as $index => $header)
                    <th scope="col" @class(['numeric' => in_array($index, $numeric, true)])>{{ $header }}</th>
                @endforeach
                @if ($linked)
                    <th scope="col"><span class="visually-hidden">Link</span></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row['cells'] as $index => $cell)
                        <td @class(['numeric' => in_array($index, $numeric, true)])>{{ $cell === '' ? '—' : $cell }}</td>
                    @endforeach
                    @if ($linked)
                        <td class="link"><a href="{{ $row['url'] }}">View</a></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <ul class="records-list">
        @foreach ($rows as $row)
            <li>
                <details>
                    <summary>{{ implode(' · ', $row['summary']) }}</summary>

                    <dl>
                        @foreach ($row['cells'] as $index => $cell)
                            @continue(in_array($index, $summaryColumns, true))
                            <div>
                                <dt>{{ $headers[$index] }}</dt>
                                <dd>{{ $cell === '' ? '—' : $cell }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @isset($row['url'])
                        <a class="button dark" href="{{ $row['url'] }}">{{ $linkLabel }}</a>
                    @endisset
                </details>
            </li>
        @endforeach
    </ul>
</div>
