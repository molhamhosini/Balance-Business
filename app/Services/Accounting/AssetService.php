<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Asset;
use App\Models\AssetDepreciation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DepreciationRun;
use App\Models\User;
use App\Support\Money;
use App\Support\Perm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Fixed assets: straight-line monthly depreciation in one run for all assets, and disposal with gain/loss. */
class AssetService
{
    public function __construct(private PostingService $posting) {}

    public function create(Company $company, array $data, User $user): Asset
    {
        $this->posting->authorize($user, Perm::ASSETS_MANAGE);
        if (Money::toCents($data['salvage_value'] ?? 0) >= Money::toCents($data['cost'] ?? 0)) {
            throw new AccountingException(__('The salvage value must be less than the cost.'));
        }
        $data['code'] = ($data['code'] ?? null) ?: $this->nextCode($company->id);
        $data['depreciation_start'] = ($data['depreciation_start'] ?? null) ?: Carbon::parse($data['purchase_date'])->startOfMonth()->toDateString();
        $asset = Asset::create($data + ['company_id' => $company->id, 'status' => 'running']);
        AuditLog::record('created', $asset, null, $asset->only(['code', 'cost', 'useful_life_months']));

        return $asset;
    }

    public function nextCode(int $companyId): string
    {
        $n = Asset::where('company_id', $companyId)->count() + 1;
        do {
            $code = 'FA-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (Asset::where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    /** Depreciation an asset still needs for the month ending $periodEnd (0 if none). */
    public function amountFor(Asset $a, string $periodEnd): int
    {
        $end = Carbon::parse($periodEnd);
        if ($a->status === 'disposed' || $a->depreciation_start->gt($end)) {
            return 0;
        }
        $remaining = $a->depreciableCents() - $a->accumulatedCents();
        if ($remaining <= 0) {
            return 0;
        }
        // last month takes the rounding remainder
        return min($a->monthlyCents(), $remaining);
    }

    /** Run depreciation for one month for every running asset: one entry DEP-YYYY-#####. */
    public function run(Company $company, string $periodEnd, User $user): DepreciationRun
    {
        $this->posting->authorize($user, Perm::ASSETS_DEPRECIATE);
        $periodEnd = Carbon::parse($periodEnd)->endOfMonth()->toDateString();

        return DB::transaction(function () use ($company, $periodEnd, $user) {
            if (DepreciationRun::where('company_id', $company->id)->where('period_end', $periodEnd)->lockForUpdate()->exists()) {
                throw new AccountingException(__('Depreciation for :m has already been run.', ['m' => substr($periodEnd, 0, 7)]));
            }
            $run = DepreciationRun::create(['company_id' => $company->id, 'period_end' => $periodEnd, 'total' => 0, 'created_by' => $user->id]);
            $lines = [];
            $total = 0;
            $branch = null;
            foreach (Asset::with('category')->where('company_id', $company->id)->where('status', 'running')->orderBy('code')->get() as $a) {
                if (AssetDepreciation::where('asset_id', $a->id)->where('period_end', $periodEnd)->exists()) {
                    continue;
                }
                $amt = $this->amountFor($a, $periodEnd);
                if ($amt <= 0) {
                    continue;
                }
                AssetDepreciation::create(['asset_id' => $a->id, 'depreciation_run_id' => $run->id, 'period_end' => $periodEnd, 'amount' => Money::fromCents($amt)]);
                $desc = __('Depreciation').' '.substr($periodEnd, 0, 7).' — '.$a->label;
                $lines[] = ['account_id' => $a->category->expense_account_id, 'debit' => Money::fromCents($amt), 'credit' => 0, 'description' => $desc, 'cost_center_id' => $a->cost_center_id];
                $lines[] = ['account_id' => $a->category->accumulated_account_id, 'debit' => 0, 'credit' => Money::fromCents($amt), 'description' => $desc];
                $total += $amt;
                $branch ??= $a->branch_id;
                if ($a->depreciableCents() - $a->accumulatedCents() <= 0) {
                    $a->update(['status' => 'fully_depreciated']);
                }
            }
            if ($total === 0) {
                throw new AccountingException(__('No asset needs depreciation for :m.', ['m' => substr($periodEnd, 0, 7)]));
            }
            $entry = $this->posting->postGenerated($run, 'depreciation', 'DEP', ['company_id' => $company->id, 'branch_id' => $branch, 'date' => $periodEnd, 'description' => __('Monthly depreciation').' '.substr($periodEnd, 0, 7)], $lines, $user);
            $run->update(['total' => Money::fromCents($total), 'journal_entry_id' => $entry->id]);
            AuditLog::record('posted', $run, null, ['total' => $run->total, 'entry' => $entry->number]);

            return $run;
        });
    }

    /**
     * Sell or scrap an asset. Proceeds go to $proceedsAccountId (cash, bank or a receivable).
     *   Dr accumulated depreciation, Dr proceeds, Cr asset cost; the difference is gain (Cr) or loss (Dr).
     */
    public function dispose(Asset $a, string $date, string $proceeds, ?int $proceedsAccountId, User $user, string $reason): Asset
    {
        $this->posting->authorize($user, Perm::ASSETS_DEPRECIATE);

        return DB::transaction(function () use ($a, $date, $proceeds, $proceedsAccountId, $user, $reason) {
            $a = Asset::whereKey($a->id)->lockForUpdate()->with('category')->firstOrFail();
            if ($a->status === 'disposed') {
                throw new AccountingException(__('This asset is already disposed.'));
            }
            $p = Money::toCents($proceeds);
            if ($p > 0 && ! $proceedsAccountId) {
                throw new AccountingException(__('Choose where the proceeds were received.'));
            }
            $cost = Money::toCents($a->cost);
            $acc = $a->accumulatedCents();
            $gain = $p - ($cost - $acc);
            $c = $a->company;
            $desc = __('Disposal of asset').' '.$a->label;
            $lines = array_values(array_filter([
                $acc > 0 ? ['account_id' => $a->category->accumulated_account_id, 'debit' => Money::fromCents($acc), 'credit' => 0] : null,
                $p > 0 ? ['account_id' => $proceedsAccountId, 'debit' => Money::fromCents($p), 'credit' => 0] : null,
                $gain < 0 ? ['account_id' => $c->systemAccount('asset_loss')->id, 'debit' => Money::fromCents(-$gain), 'credit' => 0] : null,
                ['account_id' => $a->category->asset_account_id, 'debit' => 0, 'credit' => Money::fromCents($cost)],
                $gain > 0 ? ['account_id' => $c->systemAccount('asset_gain')->id, 'debit' => 0, 'credit' => Money::fromCents($gain)] : null,
            ]));
            $entry = $this->posting->postGenerated($a, 'asset', 'DSP', ['company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'date' => $date, 'description' => $desc], array_map(fn ($l) => $l + ['description' => $desc], $lines), $user);
            $a->update(['status' => 'disposed', 'disposal_date' => $date, 'disposal_proceeds' => Money::fromCents($p), 'disposal_entry_id' => $entry->id]);
            AuditLog::record('disposed', $a, null, ['proceeds' => Money::fromCents($p), 'gain_loss' => Money::fromCents($gain), 'entry' => $entry->number], $reason);

            return $a;
        });
    }
}
