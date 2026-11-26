<div class="bb-smart">
    @foreach (($getState() ?? []) as $btn)
        <a href="{{ $btn['url'] }}" wire:navigate>
            <x-filament::icon :icon="$btn['icon']" class="h-5 w-5 text-primary-600" />
            <span><b>{{ $btn['value'] }}</b>{{ $btn['label'] }}</span>
        </a>
    @endforeach
</div>
