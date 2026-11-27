<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #111; }
    h1 { font-size: 14pt; margin: 0 0 2px; }
    .sub { color: #555; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #e6f2f1; font-weight: bold; border-bottom: 1px solid #0f766e; padding: 4px; text-align: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }}; }
    td { padding: 3px 4px; border-bottom: 0.3px solid #ddd; }
    td.n, th.n { text-align: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }}; white-space: nowrap; }
    tr.bold td { font-weight: bold; background: #f6f6f6; }
</style>
</head>
<body>
    <table style="margin-bottom:6px"><tr>
        @if (! empty($logo))<td style="width:1%;border:0;padding:0 0 0 8px"><img src="{{ $logo }}" style="height:48px"></td>@endif
        <td style="border:0;padding:0"><h1>{{ $company }}</h1>@if (! empty($trn))<div class="sub">{{ __('TRN') }}: {{ $trn }}</div>@endif</td>
    </tr></table>
    <div><b>{{ $title }}</b></div>
    <div class="sub">{{ $subtitle }}</div>
    <table>
        <thead><tr>
            @foreach ($headers as $i => $h)
                <th class="{{ in_array($i, $numeric, true) ? 'n' : '' }}">{{ $h }}</th>
            @endforeach
        </tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row['bold'] ? 'bold' : '' }}">
                    @foreach ($row['cells'] as $i => $c)
                        <td class="{{ $c['n'] ? 'n' : '' }}" @if ($loop->last && count($row['cells']) < count($headers)) colspan="{{ count($headers) - count($row['cells']) + 1 }}" @endif>{{ $c['t'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="sub" style="margin-top:10px">{{ __('Printed on') }} {{ now()->format('Y-m-d H:i') }} — {{ auth()->user()?->name }}</div>
</body>
</html>
