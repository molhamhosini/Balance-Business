<x-filament-panels::page>
    {{ $this->form }}
    @php($d = $this->getData())
    @php($L = \App\Services\Accounting\ReportService::VAT201_LABELS)
    @php($m = fn ($v) => \App\Support\Money::format($v))
    @foreach ([__('VAT on sales and all other outputs') => ['1.1','1.2','1.3','1.4','1.5','1.6','1.7','2','3','4','5','6','7','8'], __('VAT on expenses and all other inputs') => ['9','10','11'], __('Net VAT due') => ['12','13','14']] as $title => $boxes)
        <x-filament::section :heading="$title">
            <div class="bb-scroll"><table class="bb-report">
                <thead><tr><th>{{ __('Box') }}</th><th>{{ __('Description') }}</th><th class="n">{{ __('Amount (AED)') }}</th><th class="n">{{ __('VAT amount (AED)') }}</th><th class="n">{{ __('Adjustment (AED)') }}</th></tr></thead>
                <tbody>
                @foreach ($boxes as $box)
                    <tr class="{{ in_array($box, ['8','11','14']) ? 'total' : '' }}"><td>{{ $box }}</td><td>{{ $L[$box] }}</td>
                        <td class="n">{{ in_array($box, ['12','13','14']) ? '' : $m($d[$box]['amount']) }}</td><td class="n">{{ $m($d[$box]['vat']) }}</td><td class="n">{{ $m($d[$box]['adjustment']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </x-filament::section>
    @endforeach
    <p style="font-size:13px;color:rgb(100 116 139)">{{ __('Figures come from posted entries only. Standard-rated sales are placed in the box of the branch emirate. Review with your tax agent before filing on EmaraTax.') }}</p>
</x-filament-panels::page>
