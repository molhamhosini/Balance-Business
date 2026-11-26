@php($inv = $getState())
@php($m = fn ($v) => \App\Support\Money::format($v))
<div class="bb-scroll"><table class="bb-report">
    <thead><tr><th>#</th><th>{{ __('Description') }}</th><th>{{ __('Account') }}</th><th class="n">{{ __('Qty') }}</th><th>{{ __('Unit') }}</th><th class="n">{{ __('Unit price') }}</th><th>{{ __('Tax') }}</th><th class="n">{{ __('Untaxed') }}</th><th class="n">{{ __('VAT') }}</th><th class="n">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @foreach ($inv->lines()->with(['account', 'taxCode'])->get() as $i => $l)
        <tr><td>{{ $i + 1 }}</td><td>{{ $l->description }}</td><td>{{ $l->account->label }}</td>
            <td class="n">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td><td>{{ $l->unit }}</td><td class="n">{{ rtrim(rtrim(number_format((float) $l->unit_price, 4, '.', ','), '0'), '.') }}</td>
            <td>{{ $l->taxCode?->code ?? '—' }}</td><td class="n">{{ $m($l->net) }}</td><td class="n">{{ $m($l->tax) }}</td><td class="n">{{ $m($l->total) }}</td></tr>
    @endforeach
    </tbody>
</table></div>
<div class="bb-totals" style="margin-top:12px">
    <div><span>{{ __('Untaxed amount') }}</span><b>{{ $m($inv->subtotal) }}</b></div>
    <div><span>{{ __('VAT') }}</span><b>{{ $m($inv->tax_total) }}</b></div>
    <div class="grand"><span>{{ __('Total') }}</span><b>{{ $m($inv->total) }} {{ $inv->currency }}</b></div>
    @if ($inv->status === 'posted' && $inv->payment === 'credit')
        @foreach ($inv->allocations()->with(['voucher', 'creditInvoice'])->get() as $a)
            <div><span>@if($a->voucher){{ __('Paid on :d', ['d' => $a->voucher->date->format('Y-m-d')]) }} <bdi>{{ $a->voucher->number }}</bdi>@else{{ __('Credit note') }} <bdi>{{ $a->creditInvoice?->number }}</bdi>@endif</span><b>-{{ $m($a->amount) }}</b></div>
        @endforeach
        <div class="due"><span>{{ __('Amount due') }}</span><b>{{ $m($inv->openCents()) }}</b></div>
    @endif
</div>
