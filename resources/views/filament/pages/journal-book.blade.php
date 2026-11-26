<x-filament-panels::page>
    {{ $this->form }}
    @php($m = fn ($v) => (float) $v ? \App\Support\Money::format($v) : '')
    <x-filament::section>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Account') }}</th><th>{{ __('Label') }}</th><th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th></tr></thead>
            <tbody>
            @forelse ($this->getData() as $e)
                <tr class="head"><td>{{ $e->date->format('Y-m-d') }}</td>
                    <td><a href="{{ \App\Filament\Admin\Resources\JournalEntryResource::getUrl('view', ['record' => $e]) }}" wire:navigate>{{ $e->number }}</a></td>
                    <td colspan="4">{{ $e->description }} @if($e->status === 'reversed') <span class="bb-bad">({{ __('Reversed') }})</span>@endif</td></tr>
                @foreach ($e->lines as $l)
                    <tr><td></td><td></td><td>{{ $l->account->label }}</td><td>{{ $l->description }}</td><td class="n">{{ $m($l->debit) }}</td><td class="n">{{ $m($l->credit) }}</td></tr>
                @endforeach
            @empty
                <tr><td colspan="6">{{ __('No posted entries in this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
