<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .right { text-align: right; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.lines th { text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
        table.lines td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; vertical-align: top; }
        table.totals { width: 45%; margin-left: 55%; margin-top: 12px; border-collapse: collapse; }
        table.totals td { padding: 4px; }
        table.totals tr.grand td { border-top: 1px solid #1f2937; font-weight: bold; font-size: 13px; }
        .stamp { display: inline-block; border: 2px solid #047857; color: #047857; padding: 4px 10px; font-weight: bold; font-size: 14px; text-transform: uppercase; }
        .stamp.info { border-color: #1d4ed8; color: #1d4ed8; font-size: 11px; }
        .section { margin-top: 20px; }
        .section h2 { font-size: 12px; margin: 0 0 6px; }
    </style>
</head>
<body>
@yield('content')
</body>
</html>
