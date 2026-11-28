<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">{{ __('A backup is taken automatically every night at 02:00 and before every data import. Restoring replaces the whole database (all companies) with the chosen copy; the current state is saved first.') }}</x-slot>
        @php($list = $this->backups())
        @if (! $list)
            <p class="text-gray-500">{{ __('No backups yet.') }}</p>
        @else
            <div class="bb-scroll"><table class="bb-report">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Note') }}</th><th>{{ __('Size') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($list as $b)
                    <tr wire:key="bk-{{ $b['name'] }}">
                        <td style="white-space:nowrap" dir="ltr" title="{{ $b['name'] }}">{{ $b['date']->format('Y-m-d H:i') }}</td>
                        <td style="min-width:8rem"><div class="flex"><x-filament::badge :color="match($b['tag']) { 'before-import' => 'info', 'before-restore' => 'warning', 'manual' => 'success', default => 'gray' }">{{ \App\Filament\Admin\Pages\Backups::tagLabel($b['tag']) }}</x-filament::badge></div></td>
                        <td style="white-space:normal;word-break:break-word">{{ $b['note'] }}</td>
                        <td style="white-space:nowrap" dir="ltr">{{ \App\Filament\Admin\Pages\Backups::size($b['size']) }}</td>
                        <td style="white-space:nowrap"><div class="flex gap-4">
                            {{ ($this->downloadAction)(['name' => $b['name']]) }}
                            {{ ($this->restoreAction)(['name' => $b['name']]) }}
                            {{ ($this->deleteAction)(['name' => $b['name']]) }}
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
