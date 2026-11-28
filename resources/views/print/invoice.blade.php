@php($m = fn ($v) => \App\Support\Money::format($v))
@php($c = $inv->company)
@php($p = $inv->party)
@php($title = match ($inv->kind) { 'sale' => ($p?->trn ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة'), 'sale_return' => 'إشعار دائن ضريبي', 'quotation' => 'عرض سعر', 'purchase_order' => 'طلب شراء', 'purchase' => 'فاتورة شراء', default => 'إشعار مدين' })
@php($titleEn = match ($inv->kind) { 'sale' => ($p?->trn ? 'Tax Invoice' : 'Simplified Tax Invoice'), 'sale_return' => 'Tax Credit Note', 'quotation' => 'Quotation', 'purchase_order' => 'Purchase Order', 'purchase' => 'Purchase Bill', default => 'Debit Note' })
@php($num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.'))
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $inv->display_number }}</title>
<style>
:root{--brand:#0f766e;--brand-soft:#e6f2f1;--line:#d7dee5;--muted:#5b6b7b;--ink:#111827}
*{box-sizing:border-box}
html,body{margin:0;background:#e9edf1;color:var(--ink);font-family:Tahoma,"Segoe UI",Arial,sans-serif;font-size:12.5px;line-height:1.55}
.toolbar{max-width:210mm;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-start}
.toolbar button{background:var(--brand);color:#fff;border:0;border-radius:6px;padding:8px 18px;font:inherit;font-weight:bold;cursor:pointer}
.page{width:210mm;min-height:297mm;margin:12px auto 24px;background:#fff;padding:14mm 14mm 12mm;box-shadow:0 2px 14px rgba(0,0,0,.12);display:flex;flex-direction:column}
.el{direction:ltr;unicode-bidi:isolate;display:inline-block;color:var(--muted);font-size:.9em}
.n{direction:ltr;unicode-bidi:isolate;white-space:nowrap}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.co{display:flex;gap:12px;align-items:flex-start;max-width:58%}
.co img{max-height:78px;max-width:150px;object-fit:contain}
.co h1{margin:0 0 2px;font-size:17px}
.co .en{font-weight:bold;color:var(--muted);margin-bottom:2px}
.co div{color:var(--muted)}
.doc{min-width:72mm;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.doc .t{background:var(--brand);color:#fff;text-align:center;padding:8px 10px}
.doc .t b{display:block;font-size:17px}
.doc .t span{font-size:11.5px;opacity:.9;letter-spacing:.5px}
.doc table{width:100%;border-collapse:collapse}
.doc td{padding:5px 10px;border-top:1px solid var(--line)}
.doc td:first-child{color:var(--muted);width:48%}
.doc td:last-child{text-align:left;font-weight:bold}
.stamp{margin:10px 0 0;padding:6px;border:2px solid #e11d48;color:#e11d48;font-weight:bold;text-align:center;border-radius:6px}
.parties{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0 14px}
.box{border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.box h3{margin:0 0 6px;font-size:11px;color:var(--brand);letter-spacing:.3px;border-bottom:1px solid var(--brand-soft);padding-bottom:4px}
.box .name{font-weight:bold;font-size:13.5px}
.box div{margin:1px 0}
.lbl{color:var(--muted)}
table.lines{width:100%;border-collapse:collapse}
table.lines th{background:var(--brand);color:#fff;font-weight:bold;padding:7px 8px;text-align:right;font-size:11.5px;line-height:1.3}
table.lines th small{display:block;font-weight:normal;opacity:.85;font-size:10px}
table.lines td{padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:top}
table.lines tbody tr:nth-child(even) td{background:#f7f9fb}
table.lines .num{text-align:left}
table.lines .c{text-align:center;width:28px;color:var(--muted)}
.bottom{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-top:14px}
.notes{flex:1;color:var(--muted)}
.notes .footer{white-space:pre-line;border:1px dashed var(--line);border-radius:8px;padding:8px 10px;color:var(--ink)}
.totals{width:80mm;border:1px solid var(--line);border-radius:8px;overflow:hidden;border-collapse:separate;border-spacing:0}
.totals td{padding:7px 10px;border-bottom:1px solid var(--line)}
.totals td:last-child{text-align:left;font-weight:bold}
.totals tr:last-child td{border-bottom:0;background:var(--brand);color:#fff;font-size:14px}
.totals tr:last-child .el,.doc .t .el{color:#e6f2f1}
.rc{margin-top:8px;font-weight:bold}
.spacer{flex:1}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:36px}
.sign div{border-top:1px solid #9aa7b4;padding-top:6px;text-align:center;color:var(--muted)}
.foot{margin-top:18px;padding-top:8px;border-top:2px solid var(--brand);display:flex;justify-content:space-between;color:var(--muted);font-size:10.5px}
@page{size:A4;margin:0}
@media print{
  html,body{background:#fff}
  .toolbar{display:none}
  .page{margin:0;box-shadow:none;width:auto;min-height:297mm}
  table.lines th,.doc .t,.totals tr:last-child td,table.lines tbody tr:nth-child(even) td{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  table.lines tr{page-break-inside:avoid}
}
@media screen and (max-width:820px){.page{width:auto;min-height:0;margin:8px;padding:16px}.head,.bottom{flex-direction:column}.co,.doc,.totals{max-width:none;width:100%}.parties{grid-template-columns:1fr}}
</style></head>
<body>
<div class="toolbar"><button onclick="window.print()">{{ __('Print') }}</button></div>
<div class="page">
  <div class="head">
    <div class="co">
      @if($c->logo_path)<img src="{{ asset('storage/'.$c->logo_path) }}" alt="">@endif
      <div>
        <h1>{{ $c->name_ar }}</h1>
        @if($c->name_en)<div class="en">{{ $c->name_en }}</div>@endif
        @if($c->address)<div>{{ $c->address }}</div>@endif
        @if($c->phone)<div>هاتف / Tel: <span class="n">{{ $c->phone }}</span></div>@endif
        @if($c->email)<div><span class="n">{{ $c->email }}</span></div>@endif
        <div>الرقم الضريبي <span class="el">TRN</span>: <b class="n">{{ $c->trn ?: '—' }}</b></div>
      </div>
    </div>
    <div class="doc">
      <div class="t"><b>{{ $title }}</b><span>{{ $titleEn }}</span></div>
      <table>
        <tr><td>{!! $inv->isPurchaseOrder() ? 'رقم الطلب <span class="el">PO No.</span>' : 'الرقم <span class="el">No.</span>' !!}</td><td class="n">{{ $inv->display_number }}</td></tr>
        <tr><td>التاريخ <span class="el">Date</span></td><td class="n">{{ $inv->date->format('Y-m-d') }}</td></tr>
        @if($inv->kind === 'quotation' && $inv->due_date)<tr><td>صالح حتى <span class="el">Valid until</span></td><td class="n">{{ $inv->due_date->format('Y-m-d') }}</td></tr>
        @elseif($inv->kind === 'purchase_order' && $inv->due_date)<tr><td>التسليم <span class="el">Delivery</span></td><td class="n">{{ $inv->due_date->format('Y-m-d') }}</td></tr>
        @elseif($inv->due_date && $inv->payment === 'credit')<tr><td>الاستحقاق <span class="el">Due</span></td><td class="n">{{ $inv->due_date->format('Y-m-d') }}</td></tr>@endif
        <tr><td>الدفع <span class="el">Payment</span></td><td>{!! $inv->payment === 'cash' ? 'نقدي <span class="el">Cash</span>' : 'آجل <span class="el">Credit</span>' !!}</td></tr>
        @if($inv->original)<tr><td>الفاتورة الأصلية <span class="el">Original</span></td><td class="n">{{ $inv->original->number }}</td></tr>@endif
        @if($inv->supplier_invoice_number)<tr><td>فاتورة المورد <span class="el">Supplier ref.</span></td><td class="n">{{ $inv->supplier_invoice_number }}</td></tr>@endif
      </table>
    </div>
  </div>
  @if($inv->status === 'cancelled')<div class="stamp">{{ __('Cancelled') }} / CANCELLED</div>@elseif($inv->status === 'draft' && ! $inv->isQuotation())<div class="stamp">{{ __('Draft') }} — {{ __('not a tax invoice') }}</div>@endif

  <div class="parties">
    <div class="box"><h3>{!! $inv->isSale() ? 'العميل <span class="el">Bill to</span>' : 'المورد <span class="el">Supplier</span>' !!}</h3>
      <div class="name">{{ $p?->name_ar }}</div>
      @if($p?->name_en)<div>{{ $p->name_en }}</div>@endif
      @if($p?->address)<div>{{ $p->address }}</div>@endif
      @if($p?->phone)<div><span class="lbl">هاتف <span class="el">Tel</span>:</span> <span class="n">{{ $p->phone }}</span></div>@endif
      <div><span class="lbl">الرقم الضريبي <span class="el">TRN</span>:</span> <b class="n">{{ $p?->trn ?: '—' }}</b></div>
    </div>
    <div class="box"><h3>تفاصيل <span class="el">Details</span></h3>
      <div><span class="lbl">الفرع <span class="el">Branch</span>:</span> {{ $inv->branch?->name }}</div>
      <div><span class="lbl">العملة <span class="el">Currency</span>:</span> {{ $inv->currency ?: 'AED' }}</div>
      @if($inv->description)<div><span class="lbl">البيان <span class="el">Note</span>:</span> {{ $inv->description }}</div>@endif
    </div>
  </div>

  <table class="lines">
    <thead><tr>
      <th class="c">#</th><th>الوصف<small>Description</small></th><th class="num">الكمية<small>Qty</small></th><th class="num">سعر الوحدة<small>Unit price</small></th>
      <th class="num">قبل الضريبة<small>Net</small></th><th class="num">النسبة<small>Rate</small></th><th class="num">الضريبة<small>VAT</small></th><th class="num">الإجمالي<small>Total</small></th>
    </tr></thead>
    <tbody>
    @foreach ($inv->lines as $i => $l)
      <tr><td class="c">{{ $i + 1 }}</td><td>{{ $l->description }}</td>
        <td class="num"><span class="n">{{ $num($l->quantity) }}</span> {{ $l->unit }}</td>
        <td class="num"><span class="n">{{ $num($l->unit_price) }}</span></td>
        <td class="num"><span class="n">{{ $m($l->net) }}</span></td>
        <td class="num"><span class="n">{{ $l->taxCode ? ($l->taxCode->type === 'standard' || $l->taxCode->type === 'reverse_charge' ? $num($l->taxCode->rate).'%' : $l->taxCode->code) : '—' }}</span></td>
        <td class="num"><span class="n">{{ $m($l->tax) }}</span></td>
        <td class="num"><b class="n">{{ $m($l->total) }}</b></td></tr>
    @endforeach
    </tbody>
  </table>

  <div class="bottom">
    <div class="notes">
      @if($inv->lines->contains(fn ($l) => $l->taxCode?->type === 'reverse_charge'))<div class="rc">الاحتساب العكسي مطبق — Reverse charge applies</div>@endif
      @if($c->invoice_footer)<div class="footer">{{ $c->invoice_footer }}</div>@endif
    </div>
    <table class="totals">
      <tr><td>الإجمالي قبل الضريبة<br><small class="lbl">Total excl. VAT</small></td><td class="n">{{ $m($inv->subtotal) }}</td></tr>
      <tr><td>ضريبة القيمة المضافة<br><small class="lbl">VAT</small></td><td class="n">{{ $m($inv->tax_total) }}</td></tr>
      <tr><td>الإجمالي <span class="el">Total</span></td><td class="n">{{ $inv->currency ?: 'AED' }} {{ $m($inv->total) }}</td></tr>
    </table>
  </div>

  <div class="spacer"></div>
  <div class="sign"><div>{!! $inv->isSale() ? 'المستلم <span class="el">Received by</span>' : 'المورد <span class="el">Supplier</span>' !!}</div><div>{!! $inv->isSale() ? 'البائع <span class="el">Issued by</span>' : 'المعتمد <span class="el">Approved by</span>' !!}</div></div>
  <div class="foot"><span>{{ $c->name_ar }}@if($c->trn) · TRN <span class="n">{{ $c->trn }}</span>@endif</span><span class="n">{{ $inv->uuid }}</span></div>
</div>
</body></html>
