@php($lines = $getState() ?? collect())
<div class="bb-scroll">
<table class="bb-report">
    <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Label') }}</th><th>{{ __('Tax') }}</th><th class="n">{{ __('Debit') }}</th><th class="n">{{ __('Credit') }}</th></tr></thead>
    <tbody>
    @foreach ($lines as $l)
        <tr>
            <td>{{ $l->account?->label }}</td>
            <td>{{ $l->description }}</td>
            <td>{{ $l->taxCode?->code }}</td>
            <td class="n">{{ (float) $l->debit ? \App\Support\Money::format($l->debit) : '' }}</td>
            <td class="n">{{ (float) $l->credit ? \App\Support\Money::format($l->credit) : '' }}</td>
        </tr>
    @endforeach
    <tr class="total"><td colspan="3">{{ __('Total') }}</td>
        <td class="n">{{ \App\Support\Money::format($lines->sum(fn ($l) => \App\Support\Money::toCents($l->debit))) }}</td>
        <td class="n">{{ \App\Support\Money::format($lines->sum(fn ($l) => \App\Support\Money::toCents($l->credit))) }}</td></tr>
    </tbody>
</table>
</div>
