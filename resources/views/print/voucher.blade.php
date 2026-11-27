<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><title>{{ $v->display_number }}</title>
<style>
body{font-family:"IBM Plex Sans Arabic",Tahoma,sans-serif;color:#111;margin:32px;font-size:14px}
header{display:flex;justify-content:space-between;border-bottom:3px solid #0f766e;padding-bottom:12px;margin-bottom:20px}
h1{margin:0;font-size:22px}h2{margin:0;font-size:18px;color:#0f766e}
table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border:1px solid #cbd5e1;padding:8px;text-align:start}th{background:#f1f5f9;width:30%}
.sig{display:flex;justify-content:space-between;margin-top:60px}.sig div{border-top:1px solid #333;width:28%;padding-top:6px;text-align:center}
.stamp{color:#e11d48;font-weight:700;font-size:20px}
@media print{button{display:none}}
</style></head>
<body>
<button onclick="window.print()">{{ __('Print') }}</button>
<header>
  <div>@if($v->company->logo_path)<img src="{{ asset('storage/'.$v->company->logo_path) }}" alt="" style="max-height:70px;max-width:180px;display:block;margin-bottom:6px">@endif<h1>{{ $v->company->name }}</h1><div>{{ __('TRN') }}: {{ $v->company->trn ?? '—' }}</div><div>{{ $v->company->address }}</div></div>
  <div style="text-align:end"><h2>{{ __(\App\Models\Voucher::TYPES[$v->type]) }}</h2><div><b>{{ $v->display_number }}</b></div><div>{{ $v->date->format('Y-m-d') }}</div>
  @if($v->status === 'cancelled')<div class="stamp">{{ __('Cancelled') }}</div>@elseif($v->status === 'draft')<div class="stamp">{{ __('Draft') }}</div>@endif</div>
</header>
<table>
  <tr><th>{{ $v->type === 'payment' ? __('Paid to') : __('Received from') }}</th><td>{{ $v->party_name }}</td></tr>
  <tr><th>{{ __('Amount before VAT') }}</th><td>{{ \App\Support\Money::format($v->amount) }} {{ $v->company->base_currency }}</td></tr>
  <tr><th>{{ __('VAT') }} ({{ $v->taxCode?->code ?? '—' }})</th><td>{{ \App\Support\Money::format($v->tax_amount) }}</td></tr>
  <tr><th>{{ __('Total') }}</th><td><b>{{ \App\Support\Money::format($v->total) }} {{ $v->company->base_currency }}</b></td></tr>
  <tr><th>{{ __('Payment method') }}</th><td>{{ __(\App\Models\Voucher::METHODS[$v->payment_method] ?? $v->payment_method) }} {{ $v->cheque_number }}</td></tr>
  <tr><th>{{ __('Cash / bank') }}</th><td>{{ $v->cashAccount->label }}</td></tr>
  <tr><th>{{ __('Counter account') }}</th><td>{{ $v->counterAccount->label }}</td></tr>
  <tr><th>{{ __('Description') }}</th><td>{{ $v->description }}</td></tr>
  <tr><th>{{ __('Branch') }}</th><td>{{ $v->branch->name }}</td></tr>
</table>
<div class="sig"><div>{{ __('Prepared by') }}<br>{{ $v->creator?->name }}</div><div>{{ __('Approved by') }}<br>{{ $v->poster?->name }}</div><div>{{ __('Receiver') }}</div></div>
</body></html>
