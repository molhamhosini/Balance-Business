@php($m = fn ($v) => \App\Support\Money::format($v))
@php($c = $inv->company)
@php($p = $inv->party)
@php($title = match ($inv->kind) { 'sale' => ($p->trn ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة'), 'sale_return' => 'إشعار دائن ضريبي', 'quotation' => 'عرض سعر', 'purchase' => 'فاتورة شراء', default => 'إشعار مدين' })
@php($titleEn = match ($inv->kind) { 'sale' => ($p->trn ? 'Tax Invoice' : 'Simplified Tax Invoice'), 'sale_return' => 'Tax Credit Note', 'quotation' => 'Quotation', 'purchase' => 'Purchase Bill', default => 'Debit Note' })
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>{{ $inv->display_number }}</title>
<style>
body{font-family:"IBM Plex Sans Arabic",Tahoma,sans-serif;color:#111;margin:28px;font-size:13px}
header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0f766e;padding-bottom:12px;margin-bottom:16px}
h1{margin:0;font-size:20px}h2{margin:0;font-size:20px;color:#0f766e;text-align:center}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}
.box{border:1px solid #cbd5e1;border-radius:6px;padding:10px}.box h3{margin:0 0 6px;font-size:13px;color:#475569}
table{width:100%;border-collapse:collapse;margin-top:10px}td,th{border:1px solid #cbd5e1;padding:6px 8px;text-align:start}th{background:#f1f5f9;font-weight:600}
td.n,th.n{text-align:end;font-variant-numeric:tabular-nums}
.tot{width:340px;margin-inline-start:auto}.tot th{width:60%}
.stamp{color:#e11d48;font-weight:700;font-size:18px;text-align:center}
.foot{margin-top:28px;font-size:11px;color:#64748b;text-align:center}
@media print{button{display:none}body{margin:10mm}}
</style></head>
<body>
<button onclick="window.print()">{{ __('Print') }}</button>
<header>
  <div>@if($c->logo_path)<img src="{{ asset('storage/'.$c->logo_path) }}" alt="" style="max-height:70px;max-width:180px;display:block;margin-bottom:6px">@endif
    <h1>{{ $c->name_ar }}</h1>@if($c->name_en)<div>{{ $c->name_en }}</div>@endif<div>{{ $c->address }}</div>
    @if($c->phone || $c->email)<div>{{ $c->phone }} @if($c->phone && $c->email) · @endif {{ $c->email }}</div>@endif
    <div><b>TRN / الرقم الضريبي:</b> {{ $c->trn ?? '—' }}</div></div>
  <div><h2>{{ $title }}<br><small>{{ $titleEn }}</small></h2>
    @if($inv->status === 'cancelled')<div class="stamp">{{ __('Cancelled') }}</div>@elseif($inv->status === 'draft')<div class="stamp">{{ __('Draft') }} — {{ __('not a tax invoice') }}</div>@endif</div>
  <div style="text-align:end">
    <div><b>رقم الفاتورة / No.:</b> {{ $inv->display_number }}</div>
    <div><b>التاريخ / Date:</b> {{ $inv->date->format('Y-m-d') }}</div>
    @if($inv->kind === 'quotation' && $inv->due_date)<div><b>صالح حتى / Valid until:</b> {{ $inv->due_date->format('Y-m-d') }}</div>
    @elseif($inv->due_date && $inv->payment === 'credit')<div><b>الاستحقاق / Due:</b> {{ $inv->due_date->format('Y-m-d') }}</div>@endif
    @if($inv->original)<div><b>الفاتورة الأصلية / Original:</b> {{ $inv->original->number }} ({{ $inv->original->date->format('Y-m-d') }})</div>@endif
    @if($inv->supplier_invoice_number)<div><b>رقم فاتورة المورد:</b> {{ $inv->supplier_invoice_number }}</div>@endif
  </div>
</header>
<div class="meta">
  <div class="box"><h3>{{ $inv->isSale() ? 'العميل / Bill to' : 'المورد / Supplier' }}</h3>
    <div><b>{{ $p->name_ar }}</b> @if($p->name_en) — {{ $p->name_en }} @endif</div><div>{{ $p->address }}</div>
    @if($p->phone)<div>{{ $p->phone }}</div>@endif
    <div><b>TRN:</b> {{ $p->trn ?? '—' }}</div></div>
  <div class="box"><h3>الفرع / Branch</h3><div>{{ $inv->branch->name }}</div>
    <div>{{ $inv->payment === 'cash' ? 'نقدي / Cash' : 'آجل / Credit' }}</div>@if($inv->description)<div>{{ $inv->description }}</div>@endif</div>
</div>
<table>
  <thead><tr><th>#</th><th>الوصف / Description</th><th class="n">الكمية / Qty</th><th class="n">سعر الوحدة / Unit price</th><th class="n">المبلغ قبل الضريبة / Net</th><th class="n">النسبة / Rate</th><th class="n">الضريبة / VAT</th><th class="n">الإجمالي / Total</th></tr></thead>
  <tbody>
  @foreach ($inv->lines as $i => $l)
    <tr><td>{{ $i + 1 }}</td><td>{{ $l->description }}</td><td class="n">{{ rtrim(rtrim($l->quantity, '0'), '.') }} {{ $l->unit }}</td>
      <td class="n">{{ rtrim(rtrim(number_format((float) $l->unit_price, 4, '.', ','), '0'), '.') }}</td><td class="n">{{ $m($l->net) }}</td>
      <td class="n">{{ $l->taxCode ? ($l->taxCode->type === 'standard' || $l->taxCode->type === 'reverse_charge' ? rtrim(rtrim($l->taxCode->rate, '0'), '.').'%' : $l->taxCode->code) : '—' }}</td>
      <td class="n">{{ $m($l->tax) }}</td><td class="n">{{ $m($l->total) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="tot">
  <tr><th>الإجمالي قبل الضريبة / Total excl. VAT</th><td class="n">{{ $m($inv->subtotal) }}</td></tr>
  <tr><th>ضريبة القيمة المضافة / VAT</th><td class="n">{{ $m($inv->tax_total) }}</td></tr>
  <tr><th>الإجمالي شامل الضريبة / Total ({{ $inv->currency }})</th><td class="n"><b>{{ $m($inv->total) }}</b></td></tr>
</table>
@if($inv->lines->contains(fn ($l) => $l->taxCode?->type === 'reverse_charge'))<p><b>Reverse charge applies — الاحتساب العكسي مطبق</b></p>@endif
@if($c->invoice_footer)<div style="margin-top:22px;border-top:1px solid #cbd5e1;padding-top:10px;white-space:pre-line">{{ $c->invoice_footer }}</div>@endif
<div class="foot">UUID: {{ $inv->uuid }}</div>
</body></html>
