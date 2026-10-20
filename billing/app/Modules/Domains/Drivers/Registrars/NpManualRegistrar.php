<?php

namespace App\Modules\Domains\Drivers\Registrars;

use App\Enums\ReconcileStatus;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\EppCodeResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Data\TransferSyncResult;
use App\Modules\Domains\Enums\NpStage;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Services\NpWorkflow;
use Carbon\CarbonImmutable;

/**
 * Nepal .np (registrars.md §9). The registry has no API, so nothing leaves the app: register() opens the staff
 * workflow (NpWorkflow) and answers `pending`, which parks the domain in pending_registration; staff record the
 * registry's approval and sync() then reports the domain active. Registration is free at the registry, so renew()
 * only schedules the next status check. Nameserver and contact changes after approval are made by staff in the
 * registry portal (to be offered to clients in a follow-up).
 */
class NpManualRegistrar implements Registrar
{
    public function __construct(private NpWorkflow $workflow) {}

    public function name(): string
    {
        return '.np registry (manual)';
    }

    public function capabilities(): array
    {
        return ['register', 'renew', 'info', 'sync', 'reconcile'];
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'handling_fee_note', 'label' => 'Note for clients about the price', 'type' => 'string', 'help' => 'Shown on the client\'s .np request, for example that the registry is free and the price covers our handling.'],
            ['key' => 'review_cycle_years', 'label' => 'Years between registry status checks', 'type' => 'integer', 'default' => 1, 'help' => 'After approval and each renewal, staff confirm the status (and any reverification) in the registry portal.'],
            ['key' => 'staff_sla_business_days', 'label' => 'Business days before a submitted request is overdue', 'type' => 'integer', 'default' => 4],
            ['key' => 'hostmaster_email', 'label' => 'Registry hostmaster email', 'type' => 'string', 'help' => 'Shown to staff for the letters the registry takes by email, such as ownership changes.'],
        ];
    }

    /**
     * Settings are read by NpWorkflow when a step needs them.
     */
    public function configure(array $settings, bool $testMode): void {}

    public function checkAvailability(string $sld, array $tlds): array
    {
        throw new CapabilityNotSupported('availability', $this->name());
    }

    public function suggest(string $term, array $tlds, int $limit): array
    {
        throw new CapabilityNotSupported('suggestions', $this->name());
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        if (str_contains($request->domain->name, 'xn--')) {
            throw new RegistrarException('The .np registry does not accept internationalised names.');
        }

        $registration = $this->workflow->start($request->domain);

        return new RegistrarResult('pending', message: $registration->stage === NpStage::AwaitingDocuments
            ? 'Waiting for the registrant\'s documents.'
            : 'The .np request is already in progress.');
    }

    public function transfer(RegistrationRequest $request): RegistrarResult
    {
        throw new CapabilityNotSupported('transfer', $this->name());
    }

    public function renew(Domain $domain, int $years, bool $restore = false): RegistrarResult
    {
        if ($restore) {
            throw new CapabilityNotSupported('restore', $this->name());
        }

        if ($this->approved($domain) === null) {
            throw new RegistrarException('The .np registry has not approved this domain yet.');
        }

        $this->workflow->renewed($domain);

        return new RegistrarResult('completed', message: 'Renewed here; the registry charges nothing. Staff confirm the status in the registry portal.');
    }

    public function info(Domain $domain): DomainInfo
    {
        $registration = NpRegistration::query()->where('domain_id', $domain->id)->first();

        return new DomainInfo(
            status: match (true) {
                $registration === null => 'not_found',
                $registration->stage === NpStage::Approved => 'active',
                default => 'pending',
            },
            nameservers: $domain->nameservers ?? [],
            expiryDate: $domain->expiry_date ?? $registration?->registry_expires_on,
        );
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        throw new CapabilityNotSupported('nameservers', $this->name());
    }

    public function setLock(Domain $domain, bool $locked): RegistrarResult
    {
        throw new CapabilityNotSupported('lock', $this->name());
    }

    public function setIdProtection(Domain $domain, bool $enabled): RegistrarResult
    {
        throw new CapabilityNotSupported('id_protection', $this->name());
    }

    public function getContacts(Domain $domain): ContactSet
    {
        throw new CapabilityNotSupported('contacts', $this->name());
    }

    public function setContacts(Domain $domain, ContactSet $contacts): RegistrarResult
    {
        throw new CapabilityNotSupported('contacts', $this->name());
    }

    public function getEppCode(Domain $domain): EppCodeResult
    {
        throw new CapabilityNotSupported('epp', $this->name());
    }

    /**
     * The cached state: active once staff recorded the registry's approval. Nothing is read remotely.
     */
    public function sync(Domain $domain): SyncResult
    {
        $registration = $this->approved($domain);

        return new SyncResult(active: $registration !== null, expiryDate: $domain->expiry_date ?? $registration?->registry_expires_on);
    }

    public function transferSync(Domain $domain): TransferSyncResult
    {
        throw new CapabilityNotSupported('transfer_sync', $this->name());
    }

    /**
     * A lost register outcome: the request exists once the workflow was opened. A lost renewal ran when the
     * status check was rescheduled after the attempt started.
     */
    public function findSubmission(Domain $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        $registration = NpRegistration::query()->where('domain_id', $domain->id)->first();

        return match (true) {
            $registration === null => new SubmissionLookup(ReconcileStatus::NotExecuted),
            $command === 'register' && $registration->stage === NpStage::Approved => new SubmissionLookup(ReconcileStatus::Executed, $registration->registry_expires_on),
            $command === 'register' => new SubmissionLookup(ReconcileStatus::InProgress),
            $command === 'renew' && $registration->review_confirmed_at === null && $registration->updated_at?->gte($submittedAt) => new SubmissionLookup(ReconcileStatus::Executed),
            default => new SubmissionLookup(ReconcileStatus::NotExecuted),
        };
    }

    public function testConnection(): array
    {
        return ['ok' => true, 'message' => 'Nothing to connect to: staff handle .np requests in the registry portal.'];
    }

    private function approved(Domain $domain): ?NpRegistration
    {
        return NpRegistration::query()->where('domain_id', $domain->id)->where('stage', NpStage::Approved->value)->first();
    }
}
