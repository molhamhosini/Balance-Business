<x-filament-panels::page>
    {{ $this->form }}
    @if ($intro = $this->intro())
        <div class="text-sm text-gray-600 dark:text-gray-400">{!! $intro !!}</div>
    @endif
    @foreach ($this->tables() as $t)
        <x-filament::section :heading="$t['title'] ?? null">
            @if (! empty($t['description']))
                <x-slot name="description">{{ $t['description'] }}</x-slot>
            @endif
            <div class="bb-scroll"><table class="bb-report">
                <thead><tr>
                    @foreach ($t['headers'] as $i => $h)
                        <th class="{{ in_array($i, $t['numeric'] ?? [], true) ? 'n' : '' }}">{{ $h }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                @forelse ($t['rows'] as $r)
                    <tr class="{{ $r['class'] ?? '' }}">
                        @foreach ($r['cells'] as $c)
                            <td class="{{ $this::cellNumeric($c) ? 'n' : '' }} {{ is_array($c) ? ($c['cls'] ?? '') : '' }}">
                                @if (is_array($c) && ! empty($c['url']))
                                    <a href="{{ $c['url'] }}" wire:navigate>{{ $this::cellText($c) }}</a>
                                @else
                                    {{ $this::cellText($c) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($t['headers']) }}">{{ $t['empty'] ?? __('No data for this period.') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
