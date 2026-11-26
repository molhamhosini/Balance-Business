<x-filament-widgets::widget>
    <x-filament::section>
        <x-filament::tabs>
            <x-filament::tabs.item :active="$tab === 'customer'" wire:click="$set('tab', 'customer')" icon="heroicon-o-user-group">
                {{ __('Customer balances') }}
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'supplier'" wire:click="$set('tab', 'supplier')" icon="heroicon-o-truck">
                {{ __('Supplier balances') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        @php($b = $this->balances($tab))
        <div class="mt-4 max-h-96 overflow-y-auto">
            <table class="bb-report w-full">
                <thead>
                    <tr>
                        <th>{{ $tab === 'customer' ? __('Customer') : __('Supplier') }}</th>
                        <th class="text-end">{{ $tab === 'customer' ? __('Owed to us') : __('We owe') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($b['rows'] as $r)
                        <tr>
                            <td><a href="{{ $r['url'] }}" class="text-primary-600 hover:underline">{{ $r['name'] }}</a> <span class="text-xs text-gray-500">{{ $r['code'] }}</span></td>
                            <td class="text-end tabular-nums {{ $r['balance'] < 0 ? 'text-danger-600' : '' }}">{{ \App\Support\Money::format($r['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-gray-500">{{ __('No open balances.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($b['rows'])
                    <tfoot>
                        <tr class="total">
                            <td>{{ __('Total') }}</td>
                            <td class="text-end tabular-nums">{{ \App\Support\Money::format($b['total']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
