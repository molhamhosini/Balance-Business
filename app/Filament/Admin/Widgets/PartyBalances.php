<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\PartyStatement;
use App\Models\Party;
use App\Support\Money;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** Dashboard tabs: closing balance of every customer and every supplier (posted entries only). */
class PartyBalances extends Widget
{
    protected static ?int $sort = 3;
    protected static string $view = 'filament.widgets.party-balances';
    protected int|string|array $columnSpan = 'full';

    public string $tab = 'customer';

    public static function canView(): bool
    {
        return auth()->user()->canAny([Perm::REPORTS_VIEW, Perm::INVOICES_VIEW]);
    }

    /** @return array{rows: array<int, array{name: string, code: string, balance: int, url: string}>, total: int} */
    public function balances(string $type): array
    {
        $c = Filament::getTenant();
        $sums = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $c->id)->whereIn('e.status', ['posted', 'reversed'])->whereNotNull('l.party_id')
            ->groupBy('l.party_id')->selectRaw('l.party_id, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')->get()->keyBy('party_id');
        $rows = [];
        $total = 0;
        foreach (Party::where('company_id', $c->id)->where('type', $type)->orderBy('code')->get() as $p) {
            $s = $sums[$p->id] ?? null;
            $bal = $s ? Money::toCents((string) $s->d) - Money::toCents((string) $s->c) : 0;
            $bal = $type === 'supplier' ? -$bal : $bal; // what we owe suppliers shown positive
            if ($bal === 0) {
                continue;
            }
            $rows[] = ['name' => $p->name, 'code' => $p->code, 'balance' => $bal, 'url' => PartyStatement::getUrl(['party' => $p->id])];
            $total += $bal;
        }
        usort($rows, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return ['rows' => $rows, 'total' => $total];
    }
}
