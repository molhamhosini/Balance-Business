@php($steps = $getState()['steps'] ?? [])
@php($current = $getState()['current'] ?? null)
<div class="bb-statusbar">
    @foreach ($steps as $key => $label)
        <span class="{{ $key === $current ? (in_array($key, ['cancelled', 'reversed']) ? 'bad' : 'on') : '' }}">{{ $label }}</span>
    @endforeach
</div>
