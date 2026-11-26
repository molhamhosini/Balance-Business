<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($b = $this->buckets())
    @php($m = fn ($v) => $v ? \App\Support\Money::format($v) : '')
    @php($res = $this->type === 'supplier' ? \App\Filament\Admin\Resources\SupplierResource::class : \App\Filament\Admin\Resources\CustomerResource::class)
    @php($invRes = $this->type === 'supplier' ? \App\Filament\Admin\Resources\PurchaseInvoiceResource::class : \App\Filament\Admin\Resources\SalesInvoiceResource::class)
    <x-filament::section>
        <x-slot name="description">{{ __('Open credit invoices by days past their due date, as of :d. Unapplied = advances and credit notes not yet matched to an invoice.', ['d' => \Illuminate\Support\Carbon::parse($this->to)->toDateString()]) }}</x-slot>
        <div class="bb-scroll"><table class="bb-report bb-aging">
            <thead><tr><th>{{ __('Customer / supplier') }}</th>@foreach($b as $label)<th class="n">{{ $label }}</th>@endforeach<th class="n">{{ __('Unapplied') }}</th><th class="n">{{ __('Total') }}</th></tr></thead>
            <tbody>
            @forelse ($d['rows'] as $r)
                <tr class="head">
                    <td><a href="{{ $res::getUrl('view', ['record' => $r['party']]) }}" wire:navigate>{{ $r['party']->label }}</a></td>
                    @foreach(array_keys($b) as $k)<td class="n">{{ $m($r[$k]) }}</td>@endforeach
                    <td class="n">{{ $m($r['unapplied']) }}</td><td class="n"><b>{{ $m($r['total']) }}</b></td>
                </tr>
                @foreach ($r['invoices'] as $i)
                    <tr>
                        <td>&nbsp;&nbsp;<a href="{{ $invRes::getUrl('view', ['record' => $i['id']]) }}" wire:navigate>{{ $i['number'] }}</a> · {{ $i['date'] }} · {{ __('Due date') }} {{ $i['due'] }}</td>
                        @foreach(array_keys($b) as $k)<td class="n {{ $k !== 'current' && $i['bucket'] === $k ? 'late' : '' }}">{{ $i['bucket'] === $k ? $m($i['open']) : '' }}</td>@endforeach
                        <td></td><td></td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ count($b) + 3 }}">{{ __('No open balances.') }}</td></tr>
            @endforelse
            <tr class="total"><td>{{ __('Total') }}</td>@foreach(array_keys($b) as $k)<td class="n">{{ $m($d['totals'][$k]) }}</td>@endforeach<td class="n">{{ $m($d['totals']['unapplied']) }}</td><td class="n">{{ $m($d['totals']['total']) }}</td></tr>
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
