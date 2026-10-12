<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@php($primary = data_get($brand, 'theme.colors.brand', '#04326d'))
<style>
    @page { margin: 72px 28px 48px 28px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #1d2433; }
    header { position: fixed; top: -56px; left: 0; right: 0; height: 44px; border-bottom: 2px solid {{ $primary }}; }
    header .brand { font-size: 13px; font-weight: bold; color: {{ $primary }}; }
    header .title { font-size: 11px; margin-top: 2px; }
    header .right { position: absolute; right: 0; top: 0; text-align: right; color: #5b6475; }
    footer { position: fixed; bottom: -34px; left: 0; right: 0; height: 24px; border-top: 1px solid #d5dae3; color: #5b6475; font-size: 7.5px; padding-top: 4px; }
    footer .page:after { content: "Page " counter(page); }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #eef1f7; text-align: left; padding: 3px 4px; border-bottom: 1px solid #c5ccd8; font-weight: bold; }
    td { padding: 2px 4px; border-bottom: 1px solid #edf0f5; vertical-align: top; }
    td.n, th.n { text-align: right; }
    .notice { margin: 6px 0; padding: 5px 7px; border: 1px solid #e0b252; background: #fff7e3; }
    .meta { color: #5b6475; margin-bottom: 6px; }
</style>
</head>
<body>
<header>
    <div class="brand">{{ $brand['company_name'] ?? $brand['app_name'] }}</div>
    <div class="title">{{ $title }}</div>
    <div class="right">Run {{ $meta['run_at'] ?? '' }}<br>{{ $meta['runner'] ?? '' }}</div>
</header>
<footer>
    <span class="page"></span>
    &nbsp;·&nbsp; {{ $meta['report_name'] ?? $title }} (v{{ $meta['definition_version'] ?? '–' }})
    &nbsp;·&nbsp; {{ number_format($totalRows) }} row(s)
    @if(!empty($brand['legal_footer'])) &nbsp;·&nbsp; {{ $brand['legal_footer'] }} @endif
    &nbsp;·&nbsp; {{ data_get($brand, 'product.name') }} by {{ data_get($brand, 'product.vendor') }}
</footer>
<main>
    @if(!empty($meta['filters_summary']))
        <div class="meta">Filters: {{ $meta['filters_summary'] }}</div>
    @endif
    @if($truncated)
        <div class="notice">Showing the first {{ number_format($rowCount) }} of {{ number_format($totalRows) }} rows. Export to Excel or CSV for the complete result.</div>
    @endif
    <table>
        <thead>
        <tr>
            @foreach($columns as $c)
                <th class="{{ in_array($c['type'], ['integer', 'decimal', 'money', 'quantity'], true) ? 'n' : '' }}">{{ $c['label'] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        {!! $rows !!}
        </tbody>
    </table>
</main>
</body>
</html>
