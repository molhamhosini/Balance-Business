<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($c = $d['cur']) @php($p = $d['prev'])
    @php($m = fn ($v) => $v === null ? '' : \App\Support\Money::format($v))
    <x-filament::section>
        <x-slot name="heading">{{ __('Net profit') }}: <span class="{{ $c['net'] >= 0 ? 'bb-ok' : 'bb-bad' }}">{{ $m($c['net']) }}</span></x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th></th><th class="n">{{ $from }} → {{ $to }}</th>@if($p)<th class="n">{{ $p['from'] }} → {{ $p['to'] }}</th>@endif</tr></thead>
            <tbody>
            @foreach (['revenue' => __('Revenue'), 'cogs' => __('Cost of sales'), 'expense' => __('Operating expenses')] as $k => $label)
                <tr class="head"><td colspan="{{ $p ? 3 : 2 }}">{{ $label }}</td></tr>
                @foreach ($c['groups'][$k] as $code => $g)
                    <tr><td style="padding-inline-start:24px">{{ $g['label'] }}</td><td class="n">{{ $m($g['amount']) }}</td>@if($p)<td class="n">{{ $m($p['groups'][$k][$code]['amount'] ?? 0) }}</td>@endif</tr>
                @endforeach
                <tr class="total"><td>{{ __('Total') }} {{ $label }}</td><td class="n">{{ $m($c['totals'][$k]) }}</td>@if($p)<td class="n">{{ $m($p['totals'][$k]) }}</td>@endif</tr>
                @if ($k === 'cogs')
                    <tr class="total"><td>{{ __('Gross profit') }}</td><td class="n">{{ $m($c['gross']) }}</td>@if($p)<td class="n">{{ $m($p['gross']) }}</td>@endif</tr>
                @endif
            @endforeach
            <tr class="total"><td>{{ __('Net profit') }}</td><td class="n">{{ $m($c['net']) }}</td>@if($p)<td class="n">{{ $m($p['net']) }}</td>@endif</tr>
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
