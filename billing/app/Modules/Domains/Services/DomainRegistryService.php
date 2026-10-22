<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Data\Services\ProvisionResult;
use App\Modules\Domains\Actions\CreatePendingDomain;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Support\Domains\RegistrarException;
use InvalidArgumentException;

/**
 * DomainRegistry contract for Orders: create pending items at checkout, submit them on staff
 * acceptance, cancel them when the order is cancelled or marked fraud.
 */
class DomainRegistryService implements DomainRegistry
{
    public function __construct(
        private CreatePendingDomain $create,
        private SubmitToRegistrar $submit,
        private DomainStateMachine $states,
    ) {}

    public function createPending(DomainDraft $draft): int
    {
        return $this->create->handle($draft)->id;
    }

    public function process(int $domainId): ProvisionResult
    {
        $domain = Domain::query()->find($domainId);

        if ($domain === null) {
            return new ProvisionResult(false, 'Domain not found.');
        }

        try {
            return $this->submit->handle($domain);
        } catch (RegistrarException $exception) {
            return new ProvisionResult(false, $exception->getMessage());
        }
    }

    public function cancelPending(int $domainId, string $status = 'cancelled'): void
    {
        $target = DomainStatus::tryFrom($status);

        if (! in_array($target, [DomainStatus::Cancelled, DomainStatus::Fraud], true)) {
            throw new InvalidArgumentException("Pending domains can only be cancelled or marked fraud, not [{$status}].");
        }

        $domain = Domain::query()->find($domainId);

        if ($domain === null || $domain->status !== DomainStatus::Pending) {
            return;
        }

        $this->states->transition($domain, $target, 'Order '.($target === DomainStatus::Fraud ? 'marked fraud' : 'cancelled'));
    }
}
