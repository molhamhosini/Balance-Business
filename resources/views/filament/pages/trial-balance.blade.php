<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($m = fn ($v) => $v ? \App\Support\Money::format($v) : '')
    <x-filament::section>
        <x-slot name="heading">
            {{ __('Trial balance') }} —
            @if ($d['balanced']) <span class="bb-ok">{{ __('Balanced') }}</span> @else <span class="bb-bad">{{ __('Not balanced') }}</span> @endif
        </x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead>
                <tr><th rowspan="2">{{ __('Code') }}</th><th rowspan="2">{{ __('Account') }}</th>
                    @if ($mode === 'totals')<th colspan="2" class="n">{{ __('Opening') }}</th><th colspan="2" class="n">{{ __('Period movement') }}</th>@endif
                    <th colspan="2" class="n">{{ __('Closing balance') }}</th></tr>
                <tr>@if ($mode === 'totals')<th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th><th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th>@endif
                    <th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th></tr>
            </thead>
            <tbody>
            @forelse ($d['rows'] as $r)
                @php($url = \App\Filament\Admin\Pages\Ledger::getUrl(['account' => $r['account']->id, 'from' => $from, 'to' => $to, 'branch' => $branch]))
                <tr>
                    <td><a href="{{ $url }}" wire:navigate>{{ $r['account']->code }}</a></td>
                    <td style="padding-inline-start: {{ ($r['account']->level - 1) * 14 + 10 }}px">{{ $r['account']->name }}</td>
                    @if ($mode === 'totals')<td class="n">{{ $m($r['opening_dr']) }}</td><td class="n">{{ $m($r['opening_cr']) }}</td><td class="n">{{ $m($r['pd']) }}</td><td class="n">{{ $m($r['pc']) }}</td>@endif
                    <td class="n"><a href="{{ $url }}" wire:navigate>{{ $m($r['closing_dr']) }}</a></td><td class="n"><a href="{{ $url }}" wire:navigate>{{ $m($r['closing_cr']) }}</a></td>
                </tr>
            @empty
                <tr><td colspan="8">{{ __('No posted entries in this period.') }}</td></tr>
            @endforelse
            @php($t = $d['totals'])
            <tr class="total"><td></td><td>{{ __('Total') }}</td>
                @if ($mode === 'totals')<td class="n">{{ $m($t['opening_dr']) }}</td><td class="n">{{ $m($t['opening_cr']) }}</td><td class="n">{{ $m($t['pd']) }}</td><td class="n">{{ $m($t['pc']) }}</td>@endif
                <td class="n">{{ $m($t['closing_dr']) }}</td><td class="n">{{ $m($t['closing_cr']) }}</td></tr>
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
