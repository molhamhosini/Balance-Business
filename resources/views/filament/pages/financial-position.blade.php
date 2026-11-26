<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($c = $d['cur'])
    @php($p = $d['prev'])
    @php($m = fn ($v) => $v === null ? '' : \App\Support\Money::format($v))
    @php($prevSection = fn ($key) => $p ? collect($p['sections'])->firstWhere('key', $key) : null)
    <x-filament::section>
        <x-slot name="heading">
            {{ __('Statement of financial position') }} (IFRS) —
            @if ($c['balanced'])
                <span class="bb-ok">{{ __('Balanced') }}</span>
            @else
                <span class="bb-bad">{{ __('Not balanced') }}</span>
            @endif
        </x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th></th><th class="n">{{ substr((string) $to, 0, 10) }}</th>@if($p)<th class="n">{{ substr((string) $compareTo, 0, 10) }}</th>@endif</tr></thead>
            <tbody>
            @foreach ($c['sections'] as $s)
                @php($ps = $prevSection($s['key']))
                @if (isset($s['lines']))
                    <tr class="head"><td colspan="{{ $p ? 3 : 2 }}">{{ $s['label'] }}</td></tr>
                    @foreach ($s['lines'] as $code => $l)
                        <tr><td style="padding-inline-start:24px">{{ $l['label'] }}</td><td class="n">{{ $m($l['amount']) }}</td>@if($p)<td class="n">{{ $m($ps['lines'][$code]['amount'] ?? 0) }}</td>@endif</tr>
                    @endforeach
                    <tr class="total"><td>{{ __('Total :label', ['label' => $s['label']]) }}</td><td class="n">{{ $m($s['total']) }}</td>@if($p)<td class="n">{{ $m($ps['total'] ?? 0) }}</td>@endif</tr>
                @else
                    <tr class="total" @if (! empty($s['grand'])) style="font-size:1.05em;border-top:2px solid currentColor" @endif><td>{{ $s['label'] }}</td><td class="n">{{ $m($s['total']) }}</td>@if($p)<td class="n">{{ $m($ps['total'] ?? 0) }}</td>@endif</tr>
                    @if (! empty($s['grand']))
                        <tr><td colspan="{{ $p ? 3 : 2 }}" style="height:12px"></td></tr>
                    @endif
                @endif
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
