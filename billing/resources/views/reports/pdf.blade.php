<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 28px 24px 36px; }
        body { font-size: 9px; color: #1f2937; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 1px 12px 1px 0; vertical-align: top; }
        table.rows { margin-top: 10px; }
        table.rows th { text-align: left; font-size: 8px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #9ca3af; padding: 4px 3px; }
        table.rows { table-layout: fixed; }
        table.rows td { white-space: nowrap; overflow: hidden; border-bottom: 1px solid #e5e7eb; padding: 3px; vertical-align: top; }
        .right { text-align: right; }
        tr.sum td { font-weight: bold; border-top: 1px solid #6b7280; background: #f3f4f6; }
        .page { page-break-after: always; }
        .page:last-of-type { page-break-after: auto; }
        .foot { margin-top: 10px; font-size: 8px; }
    </style>
</head>
<body>
<table><tr>
    <td><h1>{{ $title }}</h1><div class="muted">{{ $brand }}</div></td>
    <td class="right muted">{{ __('Printed by :user at :time', ['user' => $printedBy, 'time' => $printedAt]) }}</td>
</tr></table>
@if ($header !== [])
    <table class="meta" style="margin-top: 8px">
        @foreach ($header as [$label, $value])
            <tr><td class="muted">{{ $label }}</td><td><strong>{{ $value }}</strong></td></tr>
        @endforeach
    </table>
@endif
@foreach ($pages as $index => $page)
    <div class="page">
        @if ($tableView)
            @include($tableView, ['report' => $report, 'columns' => $columns, 'rows' => $page['rows'], 'totals' => $loop->last ? [...$page['totals'], ...$totals] : $page['totals']])
        @else
            <table class="rows">
                <thead><tr>
                    @foreach ($columns as $i => $column)
                        <th class="{{ $right[$i] ? 'right' : '' }}">{{ $column->label }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach ($page['rows'] as $row)
                        <tr>
                            @foreach ($columns as $i => $column)
                                <td class="{{ $right[$i] ? 'right' : '' }}">{{ $row[$column->key] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @foreach ($page['totals'] as $total)
                        <tr class="sum">
                            @foreach ($columns as $i => $column)
                                <td class="{{ $right[$i] ? 'right' : '' }}">{{ $total[$column->key] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($loop->last)
                        @foreach ($totals as $total)
                            <tr class="sum">
                                @foreach ($columns as $i => $column)
                                    <td class="{{ $right[$i] ? 'right' : '' }}">{{ $total[$column->key] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        @endif
        <div class="foot muted">{{ __('Page :page of :pages', ['page' => $index + 1, 'pages' => count($pages)]) }}</div>
    </div>
@endforeach
</body>
</html>
