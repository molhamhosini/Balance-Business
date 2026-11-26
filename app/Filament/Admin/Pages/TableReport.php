<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\InventoryService;
use App\Support\Money;

/**
 * Report rendered from plain table definitions, so the screen and the Excel export share one source.
 * tables(): [['title'?, 'description'?, 'headers' => [...], 'rows' => [['cells' => [...], 'class' => 'head|total|'] ...]]]
 * A cell is a string, an int (fils, shown as money), ['q' => milli-units] (quantity), or ['t' => text, 'url' => link].
 */
abstract class TableReport extends ReportPage
{
    protected static string $view = 'filament.pages.table-report';

    abstract public function tables(): array;

    public function intro(): ?string { return null; }

    public static function cellText(mixed $c): string
    {
        return match (true) {
            is_int($c) => $c === 0 ? '' : Money::format($c),
            is_array($c) && array_key_exists('q', $c) => $c['q'] === null ? '' : number_format(InventoryService::qty($c['q']), 3),
            is_array($c) => (string) ($c['t'] ?? ''),
            default => (string) $c,
        };
    }

    public static function cellNumeric(mixed $c): bool
    {
        return is_int($c) || (is_array($c) && (array_key_exists('q', $c) || ($c['n'] ?? false)));
    }

    protected function exportRows(): array
    {
        $headers = [];
        $out = [];
        foreach ($this->tables() as $i => $t) {
            if ($i === 0) {
                $headers = $t['headers'];
            } else {
                $out[] = [];
                $out[] = [$t['title'] ?? ''];
                $out[] = $t['headers'];
            }
            foreach ($t['rows'] as $r) {
                $out[] = array_map(fn ($c) => match (true) {
                    is_int($c) => $c,
                    is_array($c) && array_key_exists('q', $c) => $c['q'] === null ? '' : (float) InventoryService::qty($c['q']),
                    default => static::cellText($c),
                }, $r['cells']);
            }
        }

        return [$headers, $out];
    }
}
