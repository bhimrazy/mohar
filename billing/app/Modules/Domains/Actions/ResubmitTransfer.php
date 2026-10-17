<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;

/**
 * After a failed transfer (status back to pending) the client or staff submits a new EPP code and the
 * transfer is retried without a new invoice (spec §4.6.5).
 */
class ResubmitTransfer
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(Domain $domain, string $eppCode, ?int $staffId = null): Domain
    {
        if ($domain->type !== 'transfer' || $domain->status !== DomainStatus::Pending || $domain->provision_invoice_id === null) {
            throw new DomainActionNotAllowed('Only a paid transfer that failed can be resubmitted.');
        }

        $domain->forceFill(['epp_code' => trim($eppCode), 'last_error' => null])->save();
        $this->activity->log('Transfer resubmitted with a new EPP code', $domain, $domain->client_id);
        TransferDomain::dispatch($domain->id, $staffId);

        return $domain;
    }
}
