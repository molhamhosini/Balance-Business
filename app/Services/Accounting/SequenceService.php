<?php

namespace App\Services\Accounting;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gapless numbering per document type and year (requirements §1, §6.4).
 * Numbers are taken inside the posting transaction with a row lock, so a
 * rolled-back posting never consumes a number.
 */
class SequenceService
{
    public const PREFIX = ['JV' => 'JV', 'RV' => 'RV', 'PV' => 'PV', 'REV' => 'REV', 'OPN' => 'OPN', 'CLS' => 'CLS', 'INV' => 'INV', 'CN' => 'CN', 'BILL' => 'BILL', 'DN' => 'DN', 'TRF' => 'TRF', 'CHQ' => 'CHQ', 'OST' => 'OST', 'STR' => 'STR', 'ISS' => 'ISS', 'ADJ' => 'ADJ', 'LC' => 'LC', 'DEP' => 'DEP', 'DSP' => 'DSP', 'QT' => 'QT', 'PO' => 'PO'];

    public function next(int $companyId, string $type, int $year): string
    {
        return sprintf('%s-%d-%05d', self::PREFIX[$type] ?? $type, $year, $this->nextNumber($companyId, $type, $year));
    }

    /** Purchase invoice reference when the user leaves it empty: INV-2026-10001, INV-2026-10002, ... */
    public function nextSupplierInvoice(int $companyId, int $year): string
    {
        return self::formatSupplierInvoice($year, $this->nextNumber($companyId, 'SINV', $year));
    }

    /** The number the next purchase invoice will get (preview only, nothing is reserved). */
    public function peekSupplierInvoice(int $companyId, int $year): string
    {
        $last = (int) DocumentSequence::where(['company_id' => $companyId, 'doc_type' => 'SINV', 'year' => $year])->value('last_number');

        return self::formatSupplierInvoice($year, $last + 1);
    }

    public static function formatSupplierInvoice(int $year, int $n): string
    {
        return sprintf('INV-%d-%05d', $year, 10000 + $n);
    }

    public function nextNumber(int $companyId, string $type, int $year): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sequence numbers must be taken inside a transaction.');
        }

        DocumentSequence::query()->insertOrIgnore(['company_id' => $companyId, 'doc_type' => $type, 'year' => $year, 'last_number' => 0]);

        $seq = DocumentSequence::where(['company_id' => $companyId, 'doc_type' => $type, 'year' => $year])->lockForUpdate()->first();
        $seq->last_number++;
        $seq->save();

        return $seq->last_number;
    }
}
