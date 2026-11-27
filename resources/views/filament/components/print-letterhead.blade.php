@php($c = \Filament\Facades\Filament::getTenant())
@if ($c)
    <div class="bb-letterhead">
        @if ($c->logo_path)<img src="{{ asset('storage/'.$c->logo_path) }}" alt="">@endif
        <div>
            <div class="bb-letterhead-name">{{ $c->name }}</div>
            <div>{{ __('TRN') }}: {{ $c->trn ?? '—' }} @if($c->address) · {{ $c->address }} @endif</div>
        </div>
    </div>
@endif
