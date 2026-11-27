<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\ReportService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * One test per acceptance criterion in section 8 of the requirements document
 * ("معايير القبول"), plus the hard rules of section 6.
 */
class AcceptanceTest extends AccountingTestCase
{
    public function test_chart_imported_from_client_workbook_with_activity_accounts(): void
    {
        $this->assertSame(132, Account::where('company_id', $this->company->id)->where('source', 'excel')->count());
        $this->assertTrue(Account::where('company_id', $this->company->id)->where('code', '1145')->exists(), 'livestock inventory');
        $this->assertTrue(Account::where('company_id', $this->company->id)->where('code', '1171')->where('is_related_party', true)->exists(), 'intercompany account');
        // Only leaves accept postings
        $this->assertFalse(Account::where('company_id', $this->company->id)->where('code', '1110')->value('is_postable'));
        $this->assertTrue(Account::where('company_id', $this->company->id)->where('code', '1111')->value('is_postable'));
        $this->assertSame('vat_output', Account::where('company_id', $this->company->id)->where('code', '2131')->value('system_key'));
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $e = $this->draft([
            ['account_id' => $this->acc('1114'), 'debit' => '100', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '99.99'],
        ]);
        $this->expectException(AccountingException::class);
        $this->posting()->post($e, $this->accountant);
    }

    public function test_parent_account_cannot_take_postings(): void
    {
        $e = $this->draft([
            ['account_id' => $this->acc('1110'), 'debit' => '100', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '100'],
        ]);
        $this->expectException(AccountingException::class);
        $this->posting()->post($e, $this->accountant);
    }

    public function test_posted_entry_cannot_be_edited_or_deleted_but_reversal_works_and_is_audited(): void
    {
        $e = $this->postedOpening();

        try {
            $e->update(['description' => 'changed']);
            $this->fail('edit allowed');
        } catch (AccountingException) {
        }
        try {
            $e->delete();
            $this->fail('delete allowed');
        } catch (AccountingException) {
        }
        try {
            $e->lines()->first()->update(['debit' => 1]);
            $this->fail('line edit allowed');
        } catch (AccountingException) {
        }

        $rev = $this->posting()->reverse($e, $this->chief, 'خطأ');
        $e->refresh();
        $this->assertSame('reversed', $e->status);
        $this->assertSame($rev->id, $e->reversed_by_id);
        $this->assertSame('posted', $rev->status);
        $this->assertTrue(AuditLog::where('action', 'reversed')->where('auditable_id', $e->id)->exists());
        // Both stay visible and net to zero
        $tb = app(ReportService::class)->trialBalance($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(0, $tb['totals']['closing_dr']);
    }

    public function test_accountant_cannot_reverse(): void
    {
        $e = $this->postedOpening();
        $this->expectException(AccountingException::class);
        $this->posting()->reverse($e, $this->accountant, 'x');
    }

    public function test_closed_period_rejects_posting_and_reopen_needs_reason(): void
    {
        $period = FiscalPeriod::where('company_id', $this->company->id)->whereDate('start_date', '2026-11-01')->first();
        app(PeriodService::class)->close($period, $this->chief);
        $e = $this->draft([
            ['account_id' => $this->acc('1121'), 'debit' => '100', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '100'],
        ]);
        try {
            $this->posting()->post($e, $this->accountant);
            $this->fail('posted into closed period');
        } catch (AccountingException $ex) {
            $this->assertStringContainsString('2026-11', $ex->getMessage());
        }
        try {
            app(PeriodService::class)->reopen($period->fresh(), $this->chief, '');
            $this->fail('reopened without reason');
        } catch (AccountingException) {
        }
        app(PeriodService::class)->reopen($period->fresh(), $this->chief, 'تصحيح');
        $this->assertSame('posted', $this->posting()->post($e, $this->accountant)->status);
        $this->assertSame('تصحيح', AuditLog::where('action', 'period_reopened')->value('reason'));
    }

    public function test_data_entry_role_cannot_post(): void
    {
        $e = $this->draft([
            ['account_id' => $this->acc('1121'), 'debit' => '100', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '100'],
        ]);
        $this->expectException(AccountingException::class);
        $this->posting()->post($e, $this->entry);
    }

    public function test_posting_depends_on_permissions_only(): void
    {
        $e = $this->draft([
            ['account_id' => $this->acc('1121'), 'debit' => '100', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '100'],
        ], by: $this->accountant);
        $this->assertSame('posted', $this->posting()->post($e, $this->accountant)->status); // the author may post their own entry
    }

    public function test_receipt_voucher_generates_the_documented_entry(): void
    {
        $this->postedOpening();
        // Vouchers carry no VAT (VAT belongs on sales/purchase invoices): Dr cash 1,000 / Cr counter account 1,000.
        $v = $this->voucher(['type' => 'receipt', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4117'), 'amount' => '1000', 'tax_code_id' => $this->tax('SR')]);
        $this->assertNull($v->tax_code_id, 'a tax code sent with a voucher is dropped');
        $this->assertSame('1000.00', $v->total);
        $v = $this->vouchers()->post($v, $this->accountant);
        $lines = $v->journalEntry->lines->map(fn ($l) => [$l->account->code, (string) $l->debit, (string) $l->credit])->all();
        $this->assertSame([['1114', '1000.00', '0.00'], ['4117', '0.00', '1000.00']], $lines);
        $this->assertSame('RV-2026-00001', $v->number);
        $this->assertTrue($v->journalEntry->isAutomatic());
    }

    public function test_payment_voucher_generates_the_documented_entry(): void
    {
        $this->postedOpening();
        $v = $this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('6220'), 'amount' => '200', 'tax_code_id' => $this->tax('SR')]);
        $v = $this->vouchers()->post($v, $this->accountant);
        $lines = $v->journalEntry->lines->map(fn ($l) => [$l->account->code, (string) $l->debit, (string) $l->credit])->all();
        $this->assertSame([['6220', '200.00', '0.00'], ['1114', '0.00', '200.00']], $lines);
    }

    public function test_automatic_entry_cannot_be_posted_or_reversed_by_hand(): void
    {
        $this->postedOpening();
        $v = $this->vouchers()->post($this->voucher(['type' => 'receipt', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4117'), 'amount' => '100']), $this->accountant);
        $this->expectException(AccountingException::class);
        $this->posting()->reverse($v->journalEntry, $this->chief, 'x');
    }

    public function test_cancelled_voucher_keeps_its_number(): void
    {
        $this->postedOpening();
        $v = $this->vouchers()->post($this->voucher(['type' => 'receipt', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4117'), 'amount' => '100']), $this->accountant);
        $v = $this->vouchers()->cancel($v, $this->chief, 'خطأ');
        $this->assertSame('cancelled', $v->status);
        $this->assertSame('RV-2026-00001', $v->number);
        $this->assertSame('reversed', $v->journalEntry->fresh()->status);
    }

    public function test_cash_box_cannot_go_negative(): void
    {
        $this->postedOpening('100');
        $v = $this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('6220'), 'amount' => '150']);
        $this->expectException(AccountingException::class);
        $this->vouchers()->post($v, $this->accountant);
    }

    public function test_payment_above_limit_needs_chief_accountant(): void
    {
        $this->postedOpening();
        $v = $this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1121'), 'counter_account_id' => $this->acc('6210'), 'amount' => '20000']);
        try {
            $this->vouchers()->post($v, $this->accountant);
            $this->fail('accountant approved above limit');
        } catch (AccountingException) {
        }
        $this->assertSame('posted', $this->vouchers()->post($v, $this->chief)->status);
    }

    public function test_numbers_are_gapless_even_after_a_failed_posting(): void
    {
        $this->postedOpening(); // JV-2026-00001
        $ok = fn () => $this->draft([['account_id' => $this->acc('1121'), 'debit' => '10', 'credit' => 0], ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '10']]);
        $bad = $this->draft([['account_id' => $this->acc('1121'), 'debit' => '10', 'credit' => 0], ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '9']]);
        $a = $this->posting()->post($ok(), $this->accountant);
        try {
            $this->posting()->post($bad, $this->accountant);
        } catch (AccountingException) {
        }
        $b = $this->posting()->post($ok(), $this->accountant);
        $this->assertSame(['JV-2026-00002', 'JV-2026-00003'], [$a->number, $b->number]);
    }

    public function test_trial_balance_and_balance_sheet_always_balance(): void
    {
        $this->postedOpening();
        $this->vouchers()->post($this->voucher(['type' => 'receipt', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4117'), 'amount' => '1234.57', 'tax_code_id' => $this->tax('SR')]), $this->accountant);
        $this->vouchers()->post($this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1121'), 'counter_account_id' => $this->acc('6220'), 'amount' => '333.33', 'tax_code_id' => $this->tax('SR')]), $this->accountant);
        $r = app(ReportService::class);
        foreach ([1, 2, 3, 4] as $level) {
            $this->assertTrue($r->trialBalance($this->company, '2026-11-01', '2026-11-30', $level)['balanced'], "level $level");
        }
        $bs = $r->balanceSheet($this->company, '2026-11-30');
        $this->assertTrue($bs['balanced']);
        $is = $r->incomeStatement($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(Money::toCents('1234.57') - Money::toCents('333.33'), $is['net']);
    }

    public function test_balance_sheet_follows_ifrs_current_and_non_current_split(): void
    {
        $this->postedOpening(); // cash 10,000 + bank 50,000 against capital 60,000
        $e = $this->draft([
            ['account_id' => $this->acc('1215'), 'debit' => '80000', 'credit' => 0],      // vehicle
            ['account_id' => $this->acc('2211'), 'debit' => 0, 'credit' => '70000'],        // long-term loan
            ['account_id' => $this->acc('1121'), 'debit' => 0, 'credit' => '10000'],
        ], '2026-11-10');
        $this->posting()->post($e, $this->accountant);
        $bs = app(ReportService::class)->balanceSheet($this->company, '2026-11-30');
        $sec = collect($bs['sections'])->keyBy('key');
        $this->assertSame(['nca', 'ca', 'ta', 'eq', 'ncl', 'cl', 'tl', 'tle'], $sec->keys()->all());
        $this->assertSame(8000000, $sec['nca']['total']);
        $this->assertSame(5000000, $sec['ca']['total']);
        $this->assertSame(13000000, $sec['ta']['total']);
        $this->assertSame(6000000, $sec['eq']['total']);
        $this->assertSame(7000000, $sec['ncl']['total']);
        $this->assertSame(0, $sec['cl']['total']);
        $this->assertSame($sec['ta']['total'], $sec['tle']['total']);
    }

    public function test_vat_201_boxes(): void
    {
        $this->postedOpening();
        $parties = app(\App\Services\Accounting\PartyService::class);
        $cust = $parties->create($this->company, ['type' => 'customer', 'name_ar' => 'عميل', 'payment_terms_days' => 30], $this->accountant);
        $supp = $parties->create($this->company, ['type' => 'supplier', 'name_ar' => 'مورد', 'payment_terms_days' => 30], $this->accountant);
        $inv = app(\App\Services\Accounting\InvoiceService::class);
        $doc = fn ($kind, $party, $acc, $amount, $tax) => $inv->post($inv->saveDraft(new \App\Models\Invoice(['company_id' => $this->company->id, 'branch_id' => $this->company->branches()->orderBy('id')->first()->id, 'kind' => $kind, 'payment' => 'credit', 'party_id' => $party->id, 'date' => '2026-11-12']),
            [['account_id' => $this->acc($acc), 'description' => 'x', 'quantity' => 1, 'unit_price' => $amount, 'tax_code_id' => $this->tax($tax)]], $this->entry), $this->accountant);
        $doc('sale', $cust, '4117', '1000', 'SR');
        $doc('sale', $cust, '4113', '400', 'ZR');
        $doc('purchase', $supp, '6220', '200', 'SR');
        // A cancelled invoice nets out of the return
        $inv->cancel($doc('sale', $cust, '4117', '500', 'SR'), $this->chief, 'x');

        $b = app(ReportService::class)->vat201($this->company, '2026-10-01', '2026-12-31');
        $this->assertSame([100000, 5000], [$b['1.1']['amount'], $b['1.1']['vat']], 'Abu Dhabi standard-rated');
        $this->assertSame(40000, $b['4']['amount'], 'zero-rated');
        $this->assertSame([20000, 1000], [$b['9']['amount'], $b['9']['vat']]);
        $this->assertSame(4000, $b['14']['vat']);
    }

    public function test_year_close_moves_result_to_retained_earnings(): void
    {
        $this->postedOpening();
        $this->vouchers()->post($this->voucher(['type' => 'receipt', 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4117'), 'amount' => '1000']), $this->accountant);
        $year = $this->company->fiscalYears()->first();
        $entry = app(PeriodService::class)->closeYear($year, $this->chief);
        $this->assertSame('closing', $entry->type);
        $r = app(ReportService::class);
        $sums = $r->sums($this->company, null, '2026-12-31');
        $re = $sums[$this->acc('3131')];
        $this->assertSame(100000, $re['c'] - $re['d']);
        $this->assertTrue($r->balanceSheet($this->company, '2026-12-31')['balanced']);
        $this->assertSame(2, $this->company->fiscalYears()->count(), 'next year opened');
        $this->assertSame(0, $r->incomeStatement($this->company, '2027-01-01', '2027-01-31')['net']);
    }

    public function test_audit_log_is_append_only(): void
    {
        $this->postedOpening();
        $log = AuditLog::first();
        $this->expectException(AccountingException::class);
        $log->update(['action' => 'x']);
    }

    public function test_audit_log_blocked_at_database_level_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL triggers only');
        }
        $this->postedOpening();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_money_is_exact(): void
    {
        $this->assertSame(30, Money::toCents('0.1') + Money::toCents('0.2'));
        $this->assertSame('0.30', Money::fromCents(Money::toCents('0.1') + Money::toCents('0.2')));
        $this->assertSame(6173, Money::percent(Money::toCents('1234.57'), '5')); // 61.7285 -> 61.73
        $this->assertSame(-5, Money::percent(-100, '5'));
    }

    public function test_branch_restricted_user_cannot_post_other_branch(): void
    {
        $main = $this->company->branches()->orderBy('id')->first();
        $other = $this->company->branches()->create(['code' => 'B2', 'name_ar' => 'فرع ٢', 'emirate' => 'DXB']);
        $this->accountant->branches()->attach($main);
        $e = $this->posting()->createDraft(['company_id' => $this->company->id, 'branch_id' => $other->id, 'date' => '2026-11-10'], [
            ['account_id' => $this->acc('1121'), 'debit' => '10', 'credit' => 0], ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => '10'],
        ], $this->entry);
        $this->expectException(AccountingException::class);
        $this->posting()->post($e, $this->accountant);
    }

    public function test_fiscal_year_can_start_any_day_and_lasts_twelve_months(): void
    {
        $y = app(PeriodService::class)->createYear($this->company, '2027-04-01');
        $this->assertSame('2028-03-31', $y->end_date->toDateString());
        $this->assertSame(12, $y->periods()->count());
    }
}
