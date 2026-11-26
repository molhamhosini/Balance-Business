<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the client's chart-of-accounts workbook. Expected columns (as in the
 * supplied file): code | name_ar | name_en | statement line | type | parent code | nature | notes.
 * Re-importing updates names/notes of existing codes and adds new ones; it never deletes.
 */
class ChartImporter
{
    private const TYPE_CLASS = ['أصول' => 'asset', 'خصوم' => 'liability', 'حقوق ملكية' => 'equity', 'إيرادات' => 'revenue'];

    public function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, false, false) as $r) {
            $code = trim((string) ($r[0] ?? ''));
            if (! preg_match('/^\d+$/', $code)) {
                continue; // title and header rows
            }
            $parent = trim((string) ($r[5] ?? ''));
            $rows[] = [
                'code' => $code,
                'name_ar' => trim((string) $r[1]),
                'name_en' => trim((string) ($r[2] ?? '')) ?: null,
                'statement_line' => trim((string) ($r[3] ?? '')) ?: null,
                'type' => trim((string) ($r[4] ?? '')),
                'parent' => preg_match('/^\d+$/', $parent) ? $parent : null,
                'nature' => str_contains((string) ($r[6] ?? ''), 'دائن') ? 'credit' : 'debit',
                'notes' => trim((string) ($r[7] ?? '')) ?: null,
            ];
        }

        return $rows;
    }

    /** @return array{created:int, updated:int} */
    public function import(Company $company, array $rows, string $source = 'excel'): array
    {
        $codes = array_column($rows, 'code');
        foreach ($rows as $r) {
            $root = $r['code'][0];
            if (! isset(Account::ROOT_CLASS[$root])) {
                throw new AccountingException(__('Account :c must start with 1-6.', ['c' => $r['code']]));
            }
            if ($r['parent'] && ! in_array($r['parent'], $codes, true) && ! Account::where('company_id', $company->id)->where('code', $r['parent'])->exists()) {
                throw new AccountingException(__('Parent :p of account :c was not found.', ['p' => $r['parent'], 'c' => $r['code']]));
            }
        }

        return DB::transaction(function () use ($company, $rows, $source) {
            $created = $updated = 0;
            // Parents first: sort by depth derived from the parent chain.
            $byCode = collect($rows)->keyBy('code');
            $depth = function ($code) use (&$depth, $byCode) {
                $p = $byCode[$code]['parent'] ?? null;

                return $p && isset($byCode[$p]) ? 1 + $depth($p) : 0;
            };
            $sorted = collect($rows)->sortBy(fn ($r) => [$depth($r['code']), $r['code']]);

            foreach ($sorted as $r) {
                $existing = Account::where('company_id', $company->id)->where('code', $r['code'])->first();
                if ($existing) {
                    $existing->update(array_intersect_key($r, array_flip(['name_ar', 'name_en', 'statement_line', 'notes'])));
                    $updated++;

                    continue;
                }
                $class = Account::ROOT_CLASS[$r['code'][0]];
                $parentId = $r['parent'] ? Account::where('company_id', $company->id)->where('code', $r['parent'])->value('id') : null;
                Account::create([
                    'company_id' => $company->id,
                    'parent_id' => $parentId,
                    'code' => $r['code'],
                    'name_ar' => $r['name_ar'],
                    'name_en' => $r['name_en'],
                    'class' => $class,
                    'nature' => $r['nature'],
                    'statement' => in_array($class, ['asset', 'liability', 'equity'], true) ? 'balance_sheet' : 'income_statement',
                    'statement_line' => $r['statement_line'],
                    'is_postable' => true,
                    'is_cash' => (bool) ($r['is_cash'] ?? false),
                    'is_bank' => (bool) ($r['is_bank'] ?? false),
                    'is_non_deductible' => (bool) ($r['is_non_deductible'] ?? false),
                    'is_related_party' => (bool) ($r['is_related_party'] ?? false),
                    'source' => $source,
                    'notes' => $r['notes'],
                ]);
                $created++;
            }
            AuditLog::record('imported', null, null, ['source' => $source, 'created' => $created, 'updated' => $updated], null, $company->id);

            return compact('created', 'updated');
        });
    }
}
