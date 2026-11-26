<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Party;
use App\Models\StockDocument;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Perm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Excel import of master data and opening balances (requirements §9): customers, suppliers, items,
 * fixed assets, open invoices at go-live, opening stock. Each template's header row carries the
 * column key in brackets, e.g. "الاسم بالعربي [name_ar]"; columns may be in any order.
 * Rows are imported one by one; a bad row is reported and skipped.
 */
class DataImporter
{
    public const TYPES = [
        'customers' => 'العملاء',
        'suppliers' => 'الموردون',
        'items' => 'الأصناف',
        'assets' => 'الأصول الثابتة',
        'open_invoices' => 'الفواتير المفتوحة الافتتاحية',
        'opening_stock' => 'المخزون الافتتاحي',
    ];

    /** key => [Arabic label, required, example] */
    public const COLUMNS = [
        'customers' => [
            'name_ar' => ['الاسم بالعربي', true, 'فندق الواحة'], 'name_en' => ['الاسم بالإنجليزي', false, 'Oasis Hotel'],
            'trn' => ['الرقم الضريبي', false, '100234567800003'], 'tax' => ['التصنيف الضريبي (SR / ZR / EX / OS)', false, 'SR'],
            'phone' => ['الهاتف', false, '025551234'], 'email' => ['البريد', false, 'info@example.com'], 'address' => ['العنوان', false, 'أبوظبي'],
            'emirate' => ['الإمارة (AUH / DXB / SHJ ...)', false, 'AUH'], 'payment_terms_days' => ['مدة السداد (يوم)', false, '30'], 'credit_limit' => ['حد الائتمان', false, '50000'],
        ],
        'items' => [
            'code' => ['رمز الصنف', true, 'P00010'], 'barcode' => ['الباركود', false, '6291234567890'], 'name_ar' => ['الاسم بالعربي', true, 'لحم غنم محلي'],
            'name_en' => ['الاسم بالإنجليزي', false, 'Local lamb'], 'type' => ['النوع (stock / service)', false, 'stock'], 'unit' => ['الوحدة الأساسية', false, 'كغ'],
            'sale_price' => ['سعر البيع', false, '45'], 'tax' => ['رمز الضريبة', false, 'SR'],
            'sales_account' => ['رمز حساب المبيعات', false, '4111'], 'inventory_account' => ['رمز حساب المخزون', false, '1141'], 'cogs_account' => ['رمز حساب التكلفة', false, '5111'],
            'unit2' => ['وحدة إضافية', false, 'كرتونة'], 'unit2_factor' => ['عدد الوحدات الأساسية فيها', false, '10'],
        ],
        'assets' => [
            'code' => ['رمز الأصل (فارغ = تلقائي)', false, ''], 'name_ar' => ['اسم الأصل', true, 'سيارة توصيل'], 'category_account' => ['رمز حساب الأصل (فئة الأصل)', true, '1215'],
            'branch' => ['رمز الفرع', false, ''], 'purchase_date' => ['تاريخ الشراء', true, '2024-03-15'], 'cost' => ['التكلفة', true, '85000'],
            'salvage_value' => ['القيمة التخريدية', false, '5000'], 'useful_life_months' => ['العمر الإنتاجي (شهر)', false, '60'],
            'opening_accumulated' => ['مجمع الإهلاك حتى تاريخ البدء', false, '25000'], 'location' => ['الموقع', false, ''], 'serial' => ['الرقم التسلسلي / اللوحة', false, ''],
        ],
        'open_invoices' => [
            'party_type' => ['النوع (customer / supplier)', true, 'customer'], 'party' => ['رمز أو اسم العميل/المورد', true, '1131-0001'],
            'number' => ['رقم الفاتورة الأصلية', true, 'INV-2025-0456'], 'date' => ['تاريخ الفاتورة', true, '2025-12-20'],
            'due_date' => ['تاريخ الاستحقاق', false, '2026-01-19'], 'amount' => ['المبلغ المتبقي (شامل الضريبة)', true, '12500'],
            'branch' => ['رمز الفرع', false, ''],
        ],
        'opening_stock' => [
            'item' => ['رمز الصنف', true, 'P00010'], 'warehouse' => ['رمز المستودع', false, ''], 'quantity' => ['الكمية (بالوحدة الأساسية)', true, '250'],
            'unit_cost' => ['تكلفة الوحدة', true, '31.50'],
        ],
    ];

    public function __construct(private PostingService $posting, private PartyService $parties, private AssetService $assets, private InventoryService $inventory, private InvoiceService $invoices) {}

    public static function columns(string $type): array
    {
        return self::COLUMNS[$type === 'suppliers' ? 'customers' : $type];
    }

    public function template(string $type): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('data')->setRightToLeft(true);
        $col = 1;
        foreach (self::columns($type) as $key => [$label, $req]) {
            $sheet->setCellValue([$col, 1], $label.($req ? ' *' : '').' ['.$key.']');
            $sheet->setCellValue([$col, 2], self::columns($type)[$key][2]);
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            $sheet->getStyle([$col, 1])->getFont()->setBold(true);
            $sheet->getStyle([$col, 2])->getFont()->getColor()->setRGB('94A3B8');
            $col++;
        }
        $sheet->setCellValue([1, 4], 'السطر الرمادي مثال: احذفه أو استبدله ببياناتك. الأعمدة المعلّمة بـ * إلزامية. التواريخ بصيغة YYYY-MM-DD.');

        return $book;
    }

    /** Rows as [excel row number => [key => value]]; the header must carry the [key] markers. */
    public function readRows(string $path, string $type): array
    {
        $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $keys = array_keys(self::columns($type));
        $map = null;
        $rows = [];
        foreach ($data as $i => $r) {
            if ($map === null) {
                $found = [];
                foreach ($r as $c => $h) {
                    if (preg_match('/\[([a-z0-9_]+)\]/', (string) $h, $m) && in_array($m[1], $keys, true)) {
                        $found[$m[1]] = $c;
                    } elseif (in_array(trim((string) $h), $keys, true)) {
                        $found[trim((string) $h)] = $c;
                    }
                }
                if ($found) {
                    $map = $found;
                }

                continue;
            }
            $row = [];
            foreach ($map as $k => $c) {
                $v = $r[$c] ?? null;
                $row[$k] = is_string($v) ? trim($v) : $v;
            }
            if (collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue;
            }
            if (str_starts_with((string) reset($row), 'السطر الرمادي')) {
                continue;
            }
            $rows[$i + 1] = $row;
        }
        if ($map === null) {
            throw new AccountingException(__('The file does not have the template header row. Download the template and fill it in.'));
        }

        return $rows;
    }

    /**
     * @param  array  $options  open_invoices / opening_stock: counter_account_id, date
     * @return array{created:int, updated:int, errors:array<int,string>, drafts:array}
     */
    public function import(Company $company, string $type, array $rows, User $user, array $options = []): array
    {
        $res = ['created' => 0, 'updated' => 0, 'errors' => [], 'drafts' => []];
        if ($type === 'opening_stock') {
            return $this->openingStock($company, $rows, $user, $options, $res);
        }
        foreach ($rows as $n => $r) {
            try {
                DB::transaction(function () use ($company, $type, $r, $user, $options, &$res) {
                    $outcome = match ($type) {
                        'customers', 'suppliers' => $this->party($company, $type === 'customers' ? 'customer' : 'supplier', $r, $user),
                        'items' => $this->item($company, $r, $user),
                        'assets' => $this->asset($company, $r, $user),
                        'open_invoices' => $this->openInvoice($company, $r, $user, $options, $res),
                    };
                    $res[$outcome]++;
                });
            } catch (AccountingException|\InvalidArgumentException $e) {
                $res['errors'][$n] = $e->getMessage();
            } catch (\Illuminate\Database\QueryException $e) {
                $res['errors'][$n] = __('Database error: :m', ['m' => str($e->getMessage())->before(' (Connection')->limit(140)]);
            }
        }
        AuditLog::record('imported', $company, null, ['type' => $type, 'created' => $res['created'], 'updated' => $res['updated'], 'errors' => count($res['errors'])]);

        return $res;
    }

    private function need(array $r, string ...$keys): void
    {
        foreach ($keys as $k) {
            if (($r[$k] ?? null) === null || $r[$k] === '') {
                throw new AccountingException(__('Missing value: :k', ['k' => $k]));
            }
        }
    }

    private function num($v): string
    {
        $v = str_replace([',', ' '], '', (string) ($v ?? '0'));
        if ($v === '') {
            return '0';
        }
        if (! is_numeric($v)) {
            throw new AccountingException(__('Not a number: :v', ['v' => $v]));
        }

        return $v;
    }

    private function date($v): string
    {
        if (is_numeric($v)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v))->toDateString();
        }
        try {
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
                $d = \DateTime::createFromFormat('!'.$f, (string) $v);
                if ($d && $d->format($f) === (string) $v) {
                    return $d->format('Y-m-d');
                }
            }

            return Carbon::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            throw new AccountingException(__('Not a date: :v', ['v' => $v]));
        }
    }

    private function account(Company $company, ?string $code, bool $postable = true): ?int
    {
        if (! $code) {
            return null;
        }
        $a = Account::where('company_id', $company->id)->where('code', $code)->first() ?? throw new AccountingException(__('Account :c not found.', ['c' => $code]));
        if ($postable && ! $a->is_postable) {
            throw new AccountingException(__('Account :code is a parent account and cannot take postings.', ['code' => $code]));
        }

        return $a->id;
    }

    private function taxId(Company $company, ?string $code): ?int
    {
        if (! $code) {
            return null;
        }

        return TaxCode::where('company_id', $company->id)->where('code', strtoupper($code))->value('id') ?? throw new AccountingException(__('Tax code :c not found.', ['c' => $code]));
    }

    private function branchId(Company $company, ?string $code): int
    {
        $q = Branch::where('company_id', $company->id);

        return ($code ? (clone $q)->where('code', $code)->value('id') : null) ?? ($code ? throw new AccountingException(__('Branch :c not found.', ['c' => $code])) : $q->orderBy('id')->value('id'));
    }

    private function party(Company $company, string $type, array $r, User $user): string
    {
        $this->need($r, 'name_ar');
        $data = array_filter([
            'name_ar' => $r['name_ar'], 'name_en' => $r['name_en'] ?? null, 'trn' => $r['trn'] ?? null, 'phone' => $r['phone'] ?? null,
            'email' => $r['email'] ?? null, 'address' => $r['address'] ?? null, 'emirate' => isset($r['emirate']) ? strtoupper((string) $r['emirate']) : null,
            'payment_terms_days' => isset($r['payment_terms_days']) && $r['payment_terms_days'] !== '' ? (int) $r['payment_terms_days'] : null,
            'credit_limit' => isset($r['credit_limit']) && $r['credit_limit'] !== '' ? $this->num($r['credit_limit']) : null,
            'tax_code_id' => $this->taxId($company, $r['tax'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
        if (isset($data['trn']) && ! preg_match('/^\d{15}$/', (string) $data['trn'])) {
            throw new AccountingException(__('The TRN must be 15 digits.'));
        }
        // the same party again (same TRN, or same name without TRN) is updated, not duplicated
        $existing = Party::where('company_id', $company->id)->where('type', $type)
            ->where(fn ($q) => isset($data['trn']) ? $q->where('trn', $data['trn']) : $q->where('name_ar', $data['name_ar']))->first();
        if ($existing) {
            $this->posting->authorize($user, Perm::PARTIES_MANAGE);
            $existing->update($data);

            return 'updated';
        }
        $this->parties->create($company, $data + ['type' => $type, 'payment_terms_days' => $data['payment_terms_days'] ?? 30], $user);

        return 'created';
    }

    private function item(Company $company, array $r, User $user): string
    {
        $this->posting->authorize($user, Perm::INVENTORY_MANAGE);
        $this->need($r, 'code', 'name_ar');
        $type = strtolower((string) ($r['type'] ?? '')) ?: 'stock';
        if (! isset(Item::TYPES[$type])) {
            throw new AccountingException(__('Type must be stock or service.'));
        }
        $item = Item::where('company_id', $company->id)->where('code', $r['code'])->first();
        $data = array_filter([
            'barcode' => $r['barcode'] ?? null, 'name_ar' => $r['name_ar'], 'name_en' => $r['name_en'] ?? null,
            'sale_price' => isset($r['sale_price']) && $r['sale_price'] !== '' ? $this->num($r['sale_price']) : null,
            'tax_code_id' => $this->taxId($company, $r['tax'] ?? null),
            'sales_account_id' => $this->account($company, $r['sales_account'] ?? null),
            'inventory_account_id' => $this->account($company, $r['inventory_account'] ?? null),
            'cogs_account_id' => $this->account($company, $r['cogs_account'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
        if (! $item || ! $item->moves()->exists()) { // type and unit are fixed once stock has moved
            $data += ['type' => $type, 'unit' => ($r['unit'] ?? null) ?: 'كغ'];
        }
        if ($item) {
            $item->update($data);
            $out = 'updated';
        } else {
            $item = Item::create($data + ['company_id' => $company->id, 'code' => $r['code'],
                'inventory_account_id' => $company->systemAccount('inventory')?->id, 'cogs_account_id' => $company->systemAccount('cogs')?->id]);
            $out = 'created';
        }
        if (! empty($r['unit2'])) {
            $item->units()->updateOrCreate(['name' => $r['unit2']], ['factor' => $this->num($r['unit2_factor'] ?? 1) ?: 1]);
        }

        return $out;
    }

    private function asset(Company $company, array $r, User $user): string
    {
        $this->need($r, 'name_ar', 'category_account', 'purchase_date', 'cost');
        if (! empty($r['code']) && Asset::where('company_id', $company->id)->where('code', $r['code'])->exists()) {
            throw new AccountingException(__('Asset :c already exists.', ['c' => $r['code']]));
        }
        $acc = $this->account($company, (string) $r['category_account']);
        $cat = AssetCategory::where('company_id', $company->id)->where('asset_account_id', $acc)->first()
            ?? throw new AccountingException(__('No asset category uses account :c.', ['c' => $r['category_account']]));
        $this->assets->create($company, array_filter([
            'code' => $r['code'] ?? null, 'name_ar' => $r['name_ar'], 'asset_category_id' => $cat->id, 'branch_id' => $this->branchId($company, $r['branch'] ?? null),
            'purchase_date' => $this->date($r['purchase_date']), 'cost' => $this->num($r['cost']), 'salvage_value' => $this->num($r['salvage_value'] ?? 0),
            'useful_life_months' => (int) (($r['useful_life_months'] ?? null) ?: $cat->useful_life_months),
            'opening_accumulated' => $this->num($r['opening_accumulated'] ?? 0), 'location' => $r['location'] ?? null, 'serial' => $r['serial'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), $user);

        return 'created';
    }

    /** An invoice still open at go-live: one line to the opening balance account, out of scope of VAT (already declared). */
    private function openInvoice(Company $company, array $r, User $user, array $options, array &$res): string
    {
        $this->need($r, 'party_type', 'party', 'number', 'date', 'amount');
        $type = strtolower((string) $r['party_type']);
        if (! in_array($type, ['customer', 'supplier'], true)) {
            throw new AccountingException(__('Type must be customer or supplier.'));
        }
        $party = Party::where('company_id', $company->id)->where('type', $type)->where(fn ($q) => $q->where('code', $r['party'])->orWhere('name_ar', $r['party'])->orWhere('name_en', $r['party']))->first()
            ?? throw new AccountingException(__('Customer / supplier :p not found. Import the parties first.', ['p' => $r['party']]));
        $counter = $options['counter_account_id'] ?? throw new AccountingException(__('Choose the opening balance account.'));
        $amount = $this->num($r['amount']);
        $kind = $type === 'customer' ? 'sale' : 'purchase';
        $inv = new Invoice([
            'company_id' => $company->id, 'branch_id' => $this->branchId($company, $r['branch'] ?? null), 'kind' => $kind, 'payment' => 'credit', 'party_id' => $party->id,
            'date' => $this->date($r['date']), 'due_date' => ! empty($r['due_date']) ? $this->date($r['due_date']) : null,
            'description' => __('Opening balance').' — '.$r['number'], 'supplier_invoice_number' => $kind === 'purchase' ? (string) $r['number'] : null,
        ]);
        $inv = $this->invoices->saveDraft($inv, [[
            'account_id' => $counter, 'description' => __('Open invoice at go-live').' '.$r['number'], 'quantity' => 1, 'unit_price' => $amount,
            'tax_code_id' => TaxCode::where('company_id', $company->id)->where('code', 'OS')->value('id'),
        ]], $user);
        $this->invoices->post($inv, $user);

        return 'created';
    }

    private function openingStock(Company $company, array $rows, User $user, array $options, array $res): array
    {
        $counter = $options['counter_account_id'] ?? throw new AccountingException(__('Choose the opening balance account.'));
        $date = $options['date'] ?? now()->toDateString();
        $byWh = [];
        foreach ($rows as $n => $r) {
            try {
                $this->need($r, 'item', 'quantity', 'unit_cost');
                $item = Item::where('company_id', $company->id)->where('code', $r['item'])->first() ?? throw new AccountingException(__('Item :c not found. Import the items first.', ['c' => $r['item']]));
                if (! $item->isStock()) {
                    throw new AccountingException(__(':i is a service, not a stock item.', ['i' => $item->code]));
                }
                $wh = ! empty($r['warehouse']) ? Warehouse::where('company_id', $company->id)->where('code', $r['warehouse'])->first() : Warehouse::where('company_id', $company->id)->orderBy('id')->first();
                if (! $wh) {
                    throw new AccountingException(__('Warehouse :c not found.', ['c' => $r['warehouse']]));
                }
                $byWh[$wh->id][] = ['item_id' => $item->id, 'quantity' => $this->num($r['quantity']), 'unit_cost' => $this->num($r['unit_cost'])];
            } catch (AccountingException $e) {
                $res['errors'][$n] = $e->getMessage();
            }
        }
        foreach ($byWh as $whId => $lines) {
            $wh = Warehouse::find($whId);
            $doc = $this->inventory->saveDocument(new StockDocument([
                'company_id' => $company->id, 'branch_id' => $wh->branch_id ?? $this->branchId($company, null), 'type' => 'opening', 'date' => $date,
                'warehouse_id' => $whId, 'counter_account_id' => $counter, 'description' => __('Imported from Excel'),
            ]), $lines, $user);
            $res['created'] += count($lines);
            $this->inventory->postDocument($doc, $user);
        }

        return $res;
    }
}
