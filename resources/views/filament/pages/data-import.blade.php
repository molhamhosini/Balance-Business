<x-filament-panels::page>
    <form wire:submit="import" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">{{ __('Import') }}</x-filament::button>
    </form>

    <x-filament::section :heading="__('Template columns')" collapsible>
        <x-slot name="description">{{ __('Download the template, fill one row per record and upload it. Columns marked * are required; the header row must be kept.') }}</x-slot>
        <div class="bb-scroll"><table class="bb-report">
            <thead><tr><th>{{ __('Column') }}</th><th>{{ __('Key') }}</th><th>{{ __('Example') }}</th></tr></thead>
            <tbody>
            @foreach ($this->columns() as $key => [$label, $req, $example])
                <tr><td>{{ $label }} @if($req)<b class="bb-bad">*</b>@endif</td><td><code>{{ $key }}</code></td><td>{{ $example }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    @if ($this->result)
        @php($r = $this->result)
        <x-filament::section :heading="__('Result')">
            <p>{{ __(':n rows read: :c added, :u updated, :e rejected.', ['n' => $r['rows'], 'c' => $r['created'], 'u' => $r['updated'], 'e' => count($r['errors'])]) }}</p>
            @if ($r['errors'])
                <table class="bb-report mt-3">
                    <thead><tr><th>{{ __('Row') }}</th><th>{{ __('Problem') }}</th></tr></thead>
                    <tbody>@foreach ($r['errors'] as $row => $msg)<tr><td>{{ $row }}</td><td class="bb-bad">{{ $msg }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
