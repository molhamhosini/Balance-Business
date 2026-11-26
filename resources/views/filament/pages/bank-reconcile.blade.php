<x-filament-panels::page>
    @php($s = $this->summary())
    @php($m = fn ($v) => \App\Support\Money::format($v))
    @php($open = $s['outstanding'])
    @php($canEdit = $this->record->status === 'open')
    <div class="grid gap-3 md:grid-cols-4">
        <x-filament::section><div class="text-xs text-gray-500">{{ __('Balance per books') }}</div><div class="text-xl font-bold">{{ $m($s['book_balance']) }}</div></x-filament::section>
        <x-filament::section><div class="text-xs text-gray-500">{{ __('Statement closing balance') }}</div><div class="text-xl font-bold">{{ $m($s['statement_closing']) }}</div></x-filament::section>
        <x-filament::section><div class="text-xs text-gray-500">{{ __('Matched lines') }}</div><div class="text-xl font-bold">{{ $s['matched'] }} / {{ $s['total'] }}</div></x-filament::section>
        <x-filament::section><div class="text-xs text-gray-500">{{ __('Unexplained difference') }}</div><div class="text-xl {{ $s['difference'] === 0 ? 'bb-ok' : 'bb-bad' }}">{{ $s['difference'] === 0 ? '✓ 0.00' : $m($s['difference']) }}</div></x-filament::section>
    </div>

    <x-filament::section :heading="__('Reconciliation statement')">
        <table class="bb-report" style="max-width:640px">
            <tr><td>{{ __('Balance per books') }}</td><td class="n">{{ $m($s['book_balance']) }}</td></tr>
            <tr><td>{{ __('Less: book items not yet on the statement (outstanding)') }}</td><td class="n">{{ $m(-$s['outstanding_cents']) }}</td></tr>
            <tr><td>{{ __('Add: statement items not yet in the books') }}</td><td class="n">{{ $m($s['unmatched_cents']) }}</td></tr>
            <tr class="total"><td>{{ __('Expected statement balance') }}</td><td class="n">{{ $m($s['book_balance'] - $s['outstanding_cents'] + $s['unmatched_cents']) }}</td></tr>
            <tr><td>{{ __('Statement closing balance') }}</td><td class="n">{{ $m($s['statement_closing']) }}</td></tr>
            @if ($s['statement_check'] !== 0)
                <tr><td class="bb-bad" colspan="2">{{ __('The statement lines do not add up from the opening to the closing balance (difference :d). Check the file or the balances.', ['d' => $m($s['statement_check'])]) }}</td></tr>
            @endif
        </table>
    </x-filament::section>

    <x-filament::section :heading="__('Statement lines')">
        <x-slot name="description">{{ __('Lines are matched to book entries with the same amount. Record bank charges or interest with a voucher or entry, then match them here.') }}</x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Description') }}</th><th>{{ __('Reference') }}</th><th class="n">{{ __('Amount') }}</th><th>{{ __('Book entry') }}</th></tr></thead>
            <tbody>
            @foreach ($this->record->lines()->with('journalLine.entry')->get() as $l)
                <tr wire:key="sl-{{ $l->id }}">
                    <td>{{ $l->date->format('Y-m-d') }}</td>
                    <td>{{ $l->description }}</td>
                    <td>{{ $l->reference }}</td>
                    <td class="n {{ (float) $l->amount < 0 ? 'bb-bad' : '' }}">{{ number_format((float) $l->amount, 2) }}</td>
                    <td>
                        @if ($l->journalLine)
                            <span class="bb-ok">✓</span>
                            <a href="{{ \App\Filament\Admin\Resources\JournalEntryResource::getUrl('view', ['record' => $l->journalLine->entry]) }}" wire:navigate>{{ $l->journalLine->entry->number }}</a>
                            · {{ $l->journalLine->entry->date->format('Y-m-d') }}
                            @if ($canEdit)<x-filament::link tag="button" color="gray" size="sm" wire:click="unmatch({{ $l->id }})">{{ __('Unmatch') }}</x-filament::link>@endif
                        @elseif ($canEdit)
                            @php($c = $this->candidates($l, $open))
                            @if ($c)
                                <select class="fi-select-input rounded-lg border-gray-300 text-sm dark:bg-gray-900" wire:change="matchLine({{ $l->id }}, $event.target.value)">
                                    <option value="">{{ __('Choose the book entry…') }}</option>
                                    @foreach ($c as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                </select>
                            @else
                                <span class="text-sm text-gray-500">{{ __('No book entry with this amount') }}</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section :heading="__('Outstanding book items')" collapsible>
        <x-slot name="description">{{ __('Posted on the bank account in the books but not found on the statement yet (e.g. cheques not yet presented, deposits in transit).') }}</x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Description') }}</th><th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th></tr></thead>
            <tbody>
            @forelse ($open as $l)
                <tr>
                    <td>{{ $l->entry->date->format('Y-m-d') }}</td>
                    <td><a href="{{ \App\Filament\Admin\Resources\JournalEntryResource::getUrl('view', ['record' => $l->entry]) }}" wire:navigate>{{ $l->entry->number }}</a></td>
                    <td>{{ $l->description ?: $l->entry->description }}</td>
                    <td class="n">{{ (float) $l->debit ? number_format((float) $l->debit, 2) : '' }}</td>
                    <td class="n">{{ (float) $l->credit ? number_format((float) $l->credit, 2) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('None.') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
