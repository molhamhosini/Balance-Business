@php($t = \App\Support\InvoiceText::labels($lang))
@php($m = fn ($v) => \App\Support\Money::format($v))
@php($c = $inv->company)
@php($p = $inv->party)
@php($num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.'))
@php($start = $lang === 'en' ? 'left' : 'right')
@php($end = $lang === 'en' ? 'right' : 'left')
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $lang === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8">
<style>
body{font-family:dejavusans;color:#111;font-size:9.5pt}
table{width:100%;border-collapse:collapse}
.head td{vertical-align:top;border:0;padding:0}
h1{margin:0;font-size:14pt}
.title{font-size:14pt;color:#fff;background:#0f766e;padding:6px;font-weight:bold;text-align:center}
.doc td{border:0.5px solid #d7dee5;padding:4px 6px}
.doc td.v{text-align:{{ $end }};font-weight:bold}
.muted{color:#5b6b7b}
.stamp{color:#e11d48;font-weight:bold;font-size:12pt;text-align:center;border:1.5px solid #e11d48;padding:4px;margin-top:8px}
.box{border:0.5px solid #d7dee5;padding:6px 8px;vertical-align:top}
.box h3{margin:0 0 4px;font-size:9.5pt;color:#0f766e}
.lines th{background:#0f766e;color:#fff;border:0.5px solid #0f766e;padding:6px;text-align:{{ $start }};font-weight:bold}
.lines td{border-bottom:0.5px solid #d7dee5;padding:5px;text-align:{{ $start }}}
.lines .n{text-align:{{ $end }};white-space:nowrap}
.tot td{border:0.5px solid #d7dee5;padding:5px}
.tot td.v{text-align:{{ $end }};font-weight:bold}
.tot tr.g td{background:#0f766e;color:#fff;font-weight:bold;font-size:11pt}
.sign td{border:0;border-top:0.5px solid #9aa7b4;text-align:center;color:#5b6b7b;padding-top:4px}
.foot{margin-top:14px;font-size:8pt;color:#5b6b7b;border-top:1.5px solid #0f766e;padding-top:4px}
</style></head>
<body>
<table class="head"><tr>
  <td style="width:58%">
    <table><tr>
      @if($logo)<td style="width:1%;border:0;padding:0 0 0 8px"><img src="{{ $logo }}" style="height:60px"></td>@endif
      <td style="border:0;padding:0 6px">
        <h1>{{ \App\Support\InvoiceText::name($c, $lang) }}</h1>
        @if($c->address)<div class="muted">{{ $c->address }}</div>@endif
        @if($c->phone)<div class="muted">{{ $t['tel'] }}: {{ $c->phone }}</div>@endif
        @if($c->email)<div class="muted">{{ $c->email }}</div>@endif
        <div>{{ $t['trn'] }}: <b>{{ $c->trn ?: '—' }}</b></div>
      </td>
    </tr></table>
  </td>
  <td style="width:42%">
    <div class="title">{{ \App\Support\InvoiceText::title($inv, $lang) }}</div>
    <table class="doc">
      <tr><td class="muted">{{ $inv->isPurchaseOrder() ? $t['po_no'] : $t['no'] }}</td><td class="v">{{ $inv->display_number }}</td></tr>
      <tr><td class="muted">{{ $t['date'] }}</td><td class="v">{{ $inv->date->format('Y-m-d') }}</td></tr>
      @if($inv->kind === 'quotation' && $inv->due_date)<tr><td class="muted">{{ $t['valid'] }}</td><td class="v">{{ $inv->due_date->format('Y-m-d') }}</td></tr>
      @elseif($inv->kind === 'purchase_order' && $inv->due_date)<tr><td class="muted">{{ $t['delivery'] }}</td><td class="v">{{ $inv->due_date->format('Y-m-d') }}</td></tr>
      @elseif($inv->due_date && $inv->payment === 'credit')<tr><td class="muted">{{ $t['due'] }}</td><td class="v">{{ $inv->due_date->format('Y-m-d') }}</td></tr>@endif
      <tr><td class="muted">{{ $t['payment'] }}</td><td class="v">{{ $inv->payment === 'cash' ? $t['cash'] : $t['credit'] }}</td></tr>
      @if($inv->original)<tr><td class="muted">{{ $t['original'] }}</td><td class="v">{{ $inv->original->number }}</td></tr>@endif
      @if($inv->supplier_invoice_number)<tr><td class="muted">{{ $t['supplier_ref'] }}</td><td class="v">{{ $inv->supplier_invoice_number }}</td></tr>@endif
    </table>
  </td>
</tr></table>
@if($inv->status === 'cancelled')<div class="stamp">{{ $t['cancelled'] }}</div>@elseif($inv->status === 'draft' && ! $inv->isQuotation())<div class="stamp">{{ $t['draft'] }}</div>@endif
<table style="margin-top:10px"><tr>
  <td class="box" style="width:50%"><h3>{{ $inv->isSale() ? $t['bill_to'] : $t['supplier'] }}</h3>
    <div><b>{{ \App\Support\InvoiceText::name($p, $lang) }}</b></div>
    @if($p?->address)<div>{{ $p->address }}</div>@endif
    @if($p?->phone)<div>{{ $t['tel'] }}: {{ $p->phone }}</div>@endif
    <div>{{ $t['trn'] }}: <b>{{ $p?->trn ?: '—' }}</b></div></td>
  <td style="width:2%;border:0"></td>
  <td class="box" style="width:48%"><h3>{{ $t['details'] }}</h3>
    <div>{{ $t['branch'] }}: {{ $inv->branch?->name }}</div>
    <div>{{ $t['currency'] }}: {{ $inv->currency ?: 'AED' }}</div>
    @if($inv->description)<div>{{ $t['note'] }}: {{ $inv->description }}</div>@endif</td>
</tr></table>
<table class="lines" style="margin-top:10px">
  <thead><tr><th>#</th><th>{{ $t['description'] }}</th><th class="n">{{ $t['qty'] }}</th><th class="n">{{ $t['unit_price'] }}</th><th class="n">{{ $t['net'] }}</th><th class="n">{{ $t['rate'] }}</th><th class="n">{{ $t['vat'] }}</th><th class="n">{{ $t['line_total'] }}</th></tr></thead>
  <tbody>
  @foreach ($inv->lines as $i => $l)
    <tr><td>{{ $i + 1 }}</td><td>{{ $l->description }}</td><td class="n">{{ $num($l->quantity) }} {{ $l->unit }}</td>
      <td class="n">{{ $num($l->unit_price) }}</td><td class="n">{{ $m($l->net) }}</td>
      <td class="n">{{ $l->taxCode ? ($l->taxCode->type === 'standard' || $l->taxCode->type === 'reverse_charge' ? $num($l->taxCode->rate).'%' : $l->taxCode->code) : '—' }}</td>
      <td class="n">{{ $m($l->tax) }}</td><td class="n"><b>{{ $m($l->total) }}</b></td></tr>
  @endforeach
  </tbody>
</table>
<table style="margin-top:10px"><tr>
  <td style="width:50%;border:0;vertical-align:top">
    @if($inv->lines->contains(fn ($l) => $l->taxCode?->type === 'reverse_charge'))<p><b>{{ $t['reverse'] }}</b></p>@endif
    @if($c->invoice_footer)<div style="border:0.5px dashed #d7dee5;padding:6px">{!! nl2br(e($c->invoice_footer)) !!}</div>@endif
  </td>
  <td style="width:5%;border:0"></td>
  <td style="width:45%;border:0;vertical-align:top">
    <table class="tot">
      <tr><td>{{ $t['subtotal'] }}</td><td class="v">{{ $m($inv->subtotal) }}</td></tr>
      <tr><td>{{ $t['vat_total'] }}</td><td class="v">{{ $m($inv->tax_total) }}</td></tr>
      <tr class="g"><td>{{ $t['total'] }}</td><td class="v">{{ $inv->currency ?: 'AED' }} {{ $m($inv->total) }}</td></tr>
    </table>
  </td>
</tr></table>
<table class="sign" style="margin-top:50px"><tr>
  <td style="width:44%">{{ $inv->isSale() ? $t['received_by'] : $t['supplier'] }}</td><td style="width:12%;border:0"></td><td style="width:44%">{{ $inv->isSale() ? $t['issued_by'] : $t['approved_by'] }}</td>
</tr></table>
<div class="foot">{{ \App\Support\InvoiceText::name($c, $lang) }}@if($c->trn) · {{ $t['trn'] }} {{ $c->trn }}@endif · {{ $inv->uuid }}</div>
</body></html>
