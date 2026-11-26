<?php

namespace Tests\Feature\Accounting;

use App\Filament\Admin\Pages;
use App\Filament\Admin\Resources;

/** Every screen renders for the chief accountant, and the data-entry role is kept out of reports. */
class ScreensTest extends AccountingTestCase
{
    public function test_screens_render(): void
    {
        $this->postedOpening();
        $this->actingAs($this->chief);
        $t = ['tenant' => $this->company];
        $urls = [
            Resources\JournalEntryResource::getUrl('index', $t),
            Resources\JournalEntryResource::getUrl('create', $t),
            Resources\JournalEntryResource::getUrl('view', $t + ['record' => \App\Models\JournalEntry::first()]),
            Resources\VoucherResource::getUrl('index', $t),
            Resources\VoucherResource::getUrl('create', $t),
            Resources\AccountResource::getUrl('index', $t),
            Resources\FiscalYearResource::getUrl('index', $t),
            Resources\AuditLogResource::getUrl('index', $t),
            Pages\TrialBalance::getUrl($t),
            Pages\Ledger::getUrl($t + ['account' => $this->acc('1114')]),
            Pages\JournalBook::getUrl($t),
            Pages\IncomeStatement::getUrl($t),
            Pages\BalanceSheet::getUrl($t),
            Pages\VatReturn::getUrl($t),
        ];
        foreach ($urls as $url) {
            $this->assertSame(200, $this->get($url)->status(), $url);
        }
    }

    public function test_data_entry_cannot_open_trial_balance(): void
    {
        $this->actingAs($this->entry);
        $this->get(Pages\TrialBalance::getUrl(['tenant' => $this->company]))->assertForbidden();
    }
}
