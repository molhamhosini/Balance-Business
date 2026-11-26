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
    public const PREFIX = ['JV' => 'JV', 'RV' => 'RV', 'PV' => 'PV', 'REV' => 'REV', 'OPN' => 'OPN', 'CLS' => 'CLS'];

    public function next(int $companyId, string $type, int $year): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sequence numbers must be taken inside a transaction.');
        }

        DocumentSequence::query()->insertOrIgnore(['company_id' => $companyId, 'doc_type' => $type, 'year' => $year, 'last_number' => 0]);

        $seq = DocumentSequence::where(['company_id' => $companyId, 'doc_type' => $type, 'year' => $year])->lockForUpdate()->first();
        $seq->last_number++;
        $seq->save();

        return sprintf('%s-%d-%05d', self::PREFIX[$type] ?? $type, $year, $seq->last_number);
    }
}
