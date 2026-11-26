<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($c = $d['cur']) @php($p = $d['prev'])
    @php($m = fn ($v) => $v === null ? '' : \App\Support\Money::format($v))
    <x-filament::section>
        <x-slot name="heading">
            {{ __('Assets') }} = {{ __('Liabilities') }} + {{ __('Equity') }}:
            @if ($c['balanced']) <span class="bb-ok">{{ __('Balanced') }}</span> @else <span class="bb-bad">{{ __('Not balanced') }}</span> @endif
        </x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th></th><th class="n">{{ substr((string) $to, 0, 10) }}</th>@if($p)<th class="n">{{ substr((string) $compareTo, 0, 10) }}</th>@endif</tr></thead>
            <tbody>
            @foreach (['asset' => __('Assets'), 'liability' => __('Liabilities'), 'equity' => __('Equity')] as $k => $label)
                <tr class="head"><td colspan="{{ $p ? 3 : 2 }}">{{ $label }}</td></tr>
                @foreach ($c['groups'][$k] as $code => $g)
                    <tr><td style="padding-inline-start:24px">{{ $g['label'] }}</td><td class="n">{{ $m($g['amount']) }}</td>@if($p)<td class="n">{{ $m($p['groups'][$k][$code]['amount'] ?? 0) }}</td>@endif</tr>
                @endforeach
                <tr class="total"><td>{{ __('Total :label', ['label' => $label]) }}</td><td class="n">{{ $m($c['totals'][$k]) }}</td>@if($p)<td class="n">{{ $m($p['totals'][$k]) }}</td>@endif</tr>
            @endforeach
            <tr class="total"><td>{{ __('Liabilities') }} + {{ __('Equity') }}</td><td class="n">{{ $m($c['totals']['liability'] + $c['totals']['equity']) }}</td>@if($p)<td class="n">{{ $m($p['totals']['liability'] + $p['totals']['equity']) }}</td>@endif</tr>
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
