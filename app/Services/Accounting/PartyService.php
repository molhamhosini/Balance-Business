<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Support\Perm;

class PartyService
{
    public function __construct(private PostingService $posting) {}

    /** Create a customer/supplier on its control account (system binding customers / suppliers). */
    public function create(Company $company, array $data, User $user): Party
    {
        $this->posting->authorize($user, Perm::PARTIES_MANAGE);
        $type = $data['type'];
        $control = $company->systemAccount($type === 'customer' ? 'customers' : 'suppliers')
            ?? throw new AccountingException(__('Bind the customers / suppliers control account in the chart of accounts first.'));
        unset($data['code']); // always automatic and sequential

        return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $data, $type, $control) {
            $party = Party::create($data + [
                'company_id' => $company->id,
                'code' => Party::nextCode($company->id, $type, $control->code),
                'account_id' => $control->id,
                'tax_code_id' => $data['tax_code_id'] ?? $company->taxCodes()->where('code', 'SR')->value('id'),
            ]);
            AuditLog::record('created', $party, null, $party->toArray());

            return $party;
        });
    }
}
