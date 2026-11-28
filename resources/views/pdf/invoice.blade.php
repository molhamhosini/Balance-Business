@php($m = fn ($v) => \App\Support\Money::format($v))
@php($c = $inv->company)
@php($p = $inv->party)
@php($title = match ($inv->kind) { 'sale' => ($p?->trn ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة'), 'sale_return' => 'إشعار دائن ضريبي', 'quotation' => 'عرض سعر', 'purchase_order' => 'طلب شراء', 'purchase' => 'فاتورة شراء', default => 'إشعار مدين' })
@php($titleEn = match ($inv->kind) { 'sale' => ($p?->trn ? 'Tax Invoice' : 'Simplified Tax Invoice'), 'sale_return' => 'Tax Credit Note', 'quotation' => 'Quotation', 'purchase_order' => 'Purchase Order', 'purchase' => 'Purchase Bill', default => 'Debit Note' })
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8">
<style>
body{font-family:dejavusans;color:#111;font-size:9.5pt}
table{width:100%;border-collapse:collapse}
.head td{vertical-align:top;border:0;padding:0}
h1{margin:0;font-size:14pt}
.title{font-size:15pt;color:#0f766e;font-weight:bold;text-align:center}
.stamp{color:#e11d48;font-weight:bold;font-size:12pt;text-align:center}
.rule{border-bottom:2px solid #0f766e;margin:8px 0 10px}
.box{border:0.5px solid #cbd5e1;padding:6px 8px;vertical-align:top}
.box h3{margin:0 0 4px;font-size:9.5pt;color:#475569}
.lines th{background:#e6f2f1;border:0.5px solid #cbd5e1;padding:5px;text-align:right;font-weight:bold}
.lines td{border:0.5px solid #cbd5e1;padding:5px;text-align:right}
.n{text-align:left !important;white-space:nowrap}
.tot td{border:0.5px solid #cbd5e1;padding:5px}
.tot td.l{background:#f1f5f9;font-weight:bold}
.foot{margin-top:18px;font-size:8pt;color:#64748b;text-align:center}
.muted{color:#475569}
</style></head>
<body>
<table class="head"><tr>
  <td style="width:38%">
    @if($logo)<img src="{{ $logo }}" style="height:55px"><br>@endif
    <h1>{{ $c->name_ar }}</h1>
    @if($c->name_en)<div>{{ $c->name_en }}</div>@endif
    @if($c->address)<div class="muted">{{ $c->address }}</div>@endif
    @if($c->phone || $c->email)<div class="muted">{{ $c->phone }} @if($c->phone && $c->email) · @endif {{ $c->email }}</div>@endif
    <div><b>TRN / الرقم الضريبي:</b> {{ $c->trn ?? '—' }}</div>
  </td>
  <td style="width:28%">
    <div class="title">{{ $title }}<br><span style="font-size:10pt">{{ $titleEn }}</span></div>
    @if($inv->status === 'cancelled')<div class="stamp">{{ __('Cancelled') }}</div>@elseif($inv->status === 'draft')<div class="stamp">{{ __('Draft') }} — {{ __('not a tax invoice') }}</div>@endif
  </td>
  <td style="width:34%;text-align:left">
    <div><b>{{ $inv->isPurchaseOrder() ? 'رقم الطلب / PO No.:' : 'رقم الفاتورة / No.:' }}</b> {{ $inv->display_number }}</div>
    <div><b>التاريخ / Date:</b> {{ $inv->date->format('Y-m-d') }}</div>
    @if($inv->kind === 'quotation' && $inv->due_date)<div><b>صالح حتى / Valid until:</b> {{ $inv->due_date->format('Y-m-d') }}</div>
    @elseif($inv->kind === 'purchase_order' && $inv->due_date)<div><b>تاريخ التسليم المتوقع / Expected delivery:</b> {{ $inv->due_date->format('Y-m-d') }}</div>
    @elseif($inv->due_date && $inv->payment === 'credit')<div><b>الاستحقاق / Due:</b> {{ $inv->due_date->format('Y-m-d') }}</div>@endif
    @if($inv->original)<div><b>الفاتورة الأصلية / Original:</b> {{ $inv->original->number }} ({{ $inv->original->date->format('Y-m-d') }})</div>@endif
    @if($inv->supplier_invoice_number)<div><b>رقم فاتورة المورد:</b> {{ $inv->supplier_invoice_number }}</div>@endif
  </td>
</tr></table>
<div class="rule"></div>
<table><tr>
  <td class="box" style="width:50%"><h3>{{ $inv->isSale() ? 'العميل / Bill to' : 'المورد / Supplier' }}</h3>
    <div><b>{{ $p?->name_ar }}</b> @if($p?->name_en) — {{ $p->name_en }} @endif</div>
    @if($p?->address)<div>{{ $p->address }}</div>@endif
    @if($p?->phone)<div>{{ $p->phone }}</div>@endif
    <div><b>TRN:</b> {{ $p?->trn ?? '—' }}</div></td>
  <td style="width:2%;border:0"></td>
  <td class="box" style="width:48%"><h3>الفرع / Branch</h3><div>{{ $inv->branch?->name }}</div>
    <div>{{ $inv->payment === 'cash' ? 'نقدي / Cash' : 'آجل / Credit' }}</div>@if($inv->description)<div>{{ $inv->description }}</div>@endif</td>
</tr></table>
<table class="lines" style="margin-top:10px">
  <thead><tr><th>#</th><th>الوصف / Description</th><th class="n">الكمية / Qty</th><th class="n">سعر الوحدة / Unit price</th><th class="n">قبل الضريبة / Net</th><th class="n">النسبة / Rate</th><th class="n">الضريبة / VAT</th><th class="n">الإجمالي / Total</th></tr></thead>
  <tbody>
  @foreach ($inv->lines as $i => $l)
    <tr><td>{{ $i + 1 }}</td><td>{{ $l->description }}</td><td class="n">{{ rtrim(rtrim($l->quantity, '0'), '.') }} {{ $l->unit }}</td>
      <td class="n">{{ rtrim(rtrim(number_format((float) $l->unit_price, 4, '.', ','), '0'), '.') }}</td><td class="n">{{ $m($l->net) }}</td>
      <td class="n">{{ $l->taxCode ? ($l->taxCode->type === 'standard' || $l->taxCode->type === 'reverse_charge' ? rtrim(rtrim($l->taxCode->rate, '0'), '.').'%' : $l->taxCode->code) : '—' }}</td>
      <td class="n">{{ $m($l->tax) }}</td><td class="n">{{ $m($l->total) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="tot" style="width:55%;margin-top:10px;margin-right:auto;margin-left:0">
  <tr><td class="l">الإجمالي قبل الضريبة / Total excl. VAT</td><td class="n">{{ $m($inv->subtotal) }}</td></tr>
  <tr><td class="l">ضريبة القيمة المضافة / VAT</td><td class="n">{{ $m($inv->tax_total) }}</td></tr>
  <tr><td class="l">الإجمالي شامل الضريبة / Total ({{ $inv->currency }})</td><td class="n"><b>{{ $m($inv->total) }}</b></td></tr>
</table>
@if($inv->lines->contains(fn ($l) => $l->taxCode?->type === 'reverse_charge'))<p><b>Reverse charge applies — الاحتساب العكسي مطبق</b></p>@endif
@if($c->invoice_footer)<div style="margin-top:16px;border-top:0.5px solid #cbd5e1;padding-top:8px">{!! nl2br(e($c->invoice_footer)) !!}</div>@endif
<div class="foot">UUID: {{ $inv->uuid }}</div>
</body></html>
