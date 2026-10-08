@use('App\Tachograph\Reporting\Format')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Tachograph report – {{ $report->driver['id'] }} – {{ substr($report->period['start'], 0, 10) }}</title>
    <style>
        {!! $css !!}
        /* Fallback styles when the compiled CSS is not available, and print tweaks. */
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #1f2937; margin: 2rem; line-height: 1.4; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 4px 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; font-weight: 600; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .text-right { text-align: right; }
        [data-status] { display: inline-block; border-radius: 9999px; padding: 1px 8px; font-size: 12px; font-weight: 500; background: #f3f4f6; }
        [data-status="VIOLATION"] { background: #fee2e2; color: #991b1b; }
        [data-status="INCOMPLETE_DATA"] { background: #fef3c7; color: #92400e; }
        [data-status="WARNING"] { background: #fef9c3; color: #854d0e; }
        [data-status="COMPLIANT"] { background: #dcfce7; color: #166534; }
        .tacho-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .tacho-totals dl { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; }
        [data-card] { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; }
        section { margin-top: 2rem; }
        @media print { body { margin: 0; } details { display: block; } details > summary { display: none; } }
    </style>
</head>
<body class="bg-white text-gray-800">
    <header>
        <h1 class="text-2xl font-medium" style="margin:0">Tachograph report</h1>
        <p class="text-gray-500" style="margin:.25rem 0 0">
            {{ $report->driver['name'] ?: $report->driver['id'] }} ·
            {{ Format::local($report->period['start'], $report->displayTimezone, 'd M Y') }} –
            {{ Format::local((new DateTimeImmutable($report->period['end']))->modify('-1 second')->format('c'), $report->displayTimezone, 'd M Y') }}
        </p>
    </header>
    <main class="mt-6">
        @include('tachograph.reports.partials.body')
    </main>
</body>
</html>
