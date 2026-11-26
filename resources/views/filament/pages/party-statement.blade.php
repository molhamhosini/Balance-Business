<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($p = $this->getParty())
    @php($m = fn ($v) => $v ? \App\Support\Money::format($v) : '')
    @php($bal = fn ($v) => $v === 0 ? '0.00' : \App\Support\Money::format(abs($v)).' '.($v > 0 ? __('Dr') : __('Cr')))
    @if (! $d)
        <x-filament::section>{{ __('Choose a customer or supplier to see the statement.') }}</x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $p->label }} @if($p->trn) · {{ __('TRN') }} {{ $p->trn }} @endif</x-slot>
            <x-slot name="description">{{ $this->from }} → {{ $this->to }} · {{ __('Closing balance') }}: {{ $bal($d['closing']) }}</x-slot>
            <div class="bb-scroll"><table class="bb-report">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th><th class="n">{{ __('Balance') }}</th></tr></thead>
                <tbody>
                    <tr class="head"><td colspan="5">{{ __('Opening balance') }}</td><td class="n">{{ $bal($d['opening']) }}</td></tr>
                    @foreach ($d['rows'] as $r)
                        <tr>
                            <td>{{ $r['date'] }}</td>
                            <td><a href="{{ \App\Filament\Admin\Resources\JournalEntryResource::getUrl('view', ['record' => $r['entry_id']]) }}" wire:navigate>{{ $r['number'] }}</a></td>
                            <td>{{ $r['description'] }}</td>
                            <td class="n">{{ $m($r['debit']) }}</td><td class="n">{{ $m($r['credit']) }}</td><td class="n">{{ $bal($r['balance']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total"><td colspan="3">{{ __('Total') }}</td><td class="n">{{ $m($d['debit']) }}</td><td class="n">{{ $m($d['credit']) }}</td><td class="n">{{ $bal($d['closing']) }}</td></tr>
                </tbody>
            </table></div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
