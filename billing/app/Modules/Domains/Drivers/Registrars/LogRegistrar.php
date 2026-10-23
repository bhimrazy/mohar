<?php

namespace App\Modules\Domains\Drivers\Registrars;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\LinksExistingOrders;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\RestoresDomains;
use App\Contracts\Domains\Registrar\SuggestsNames;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\EppCodeResult;
use App\Data\Domains\Registrar\LinkedOrder;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\SyncResult;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Enums\ReconcileStatus;
use App\Modules\Domains\Support\DomainName;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Deterministic demo registrar that only writes to the application log.
 *
 * Behaviour is driven by the second-level label so demos and tests are predictable:
 *  - contains "taken"    → registered (not available); "reserved" → reserved; "premium" → premium
 *  - contains "fail"     → register/transfer/renew are rejected ("transferfail": transfer sync fails)
 *  - contains "timeout"  → register/transfer time out (result unknown)
 *  - contains "slow"     → registration accepted asynchronously (pending)
 *  - contains "email"    → EPP code is emailed to the registrant instead of returned
 *  - contains "gone"     → sync reports the domain transferred away
 * After a lost outcome, findSubmission reports a "timeout" registration as executed (a transfer as in progress), a
 * "slow" one as in progress, a "taken" name as a conflict (registered elsewhere), a renewal as executed and anything
 * else as not executed.
 * The "simulate" setting forces every write command to fail ("error") or time out ("timeout").
 *
 * The reference implementation: it implements every capability, so the contract dataset covers each of them.
 */
class LogRegistrar implements ChecksAvailability, DeclaresTldAttributes, LinksExistingOrders, ManagesContacts, ManagesIdProtection, ManagesLock, ManagesNameservers, ProvidesEppCode, ReconcilesSubmissions, RegistrarDriver, RestoresDomains, SuggestsNames, SyncsDomains, TestsRegistrarConnection, TransfersDomains
{
    public function key(): string
    {
        return 'log';
    }

    public function name(): string
    {
        return 'Log (demo)';
    }

    public function settingsFields(): array
    {
        return [
            new SettingField('api_key', 'API key', FieldType::Secret, help: 'Any value of 8 characters or more works; "invalid-key" fails the connection test.'),
            new SettingField('simulate', 'Simulate failures', FieldType::Select, options: ['none' => 'Never fail', 'error' => 'Reject every command', 'timeout' => 'Time out every command']),
        ];
    }

    /**
     * The reference driver asks for nothing.
     *
     * @return list<SettingField>
     */
    public function tldAttributeFields(string $tld): array
    {
        return [];
    }

    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
    {
        $this->log('availability', ['sld' => $sld, 'tlds' => $tlds]);

        return array_map(fn (string $tld): AvailabilityResult => $this->availabilityOf($sld, $tld), $tlds);
    }

    public function suggest(RegistrarConfig $config, string $term, array $tlds, int $limit): array
    {
        $this->log('suggest', ['term' => $term, 'tlds' => $tlds]);
        $suggestions = [];
        $label = DomainName::toUnicode($term);

        foreach ([$label, "get{$label}", "{$label}hq", "my{$label}", "{$label}online", "try{$label}"] as $unicode) {
            try {
                $candidate = DomainName::toAscii($unicode);
            } catch (InvalidArgumentException) {
                continue;
            }

            foreach ($tlds as $tld) {
                $result = $this->availabilityOf($candidate, $tld);

                if ($result->isAvailable()) {
                    $suggestions[] = $result;
                }

                if (count($suggestions) >= $limit) {
                    return $suggestions;
                }
            }
        }

        return $suggestions;
    }

    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        $this->guardWrite($config, 'register', $request->domain);

        if (str_contains($request->domain->sld, 'slow')) {
            return new RegistrarResult('pending', message: 'Registration accepted; awaiting registry confirmation.');
        }

        return new RegistrarResult('completed', null, 'Domain registered.');
    }

    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        $this->guardWrite($config, 'transfer', $request->domain);

        return new RegistrarResult('pending', message: 'Transfer requested; awaiting approval by the losing registrar.');
    }

    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        $this->guardWrite($config, 'renew', $domain, timeouts: false);

        return new RegistrarResult('completed', $domain->expiryDate?->addYearsNoOverflow($years), 'Domain renewed.');
    }

    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        $this->guardWrite($config, 'restore', $domain, timeouts: false);

        return new RegistrarResult('completed', $domain->expiryDate?->addYearsNoOverflow($years), 'Domain restored and renewed.');
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
        $this->log('info', ['domain' => $domain->name]);

        $status = match ($domain->status) {
            'pending' => str_contains($domain->sld, 'timeout') ? 'active' : 'not_found',
            'pending_transfer' => 'pending',
            'expired' => 'expired',
            'transferred_away' => 'transferred_away',
            'cancelled', 'fraud' => 'cancelled',
            default => 'active',
        };

        $expiry = $domain->expiryDate;

        return new DomainInfo(
            status: $status,
            nameservers: $domain->nameservers,
            locked: $domain->locked ?? false,
            expiryDate: $expiry,
            idProtection: $domain->idProtection,
        );
    }

    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult
    {
        $this->guardWrite($config, 'nameservers', $domain, timeouts: false);

        return new RegistrarResult(message: 'Nameservers updated.');
    }

    public function setLock(RegistrarConfig $config, DomainRef $domain, bool $locked): RegistrarResult
    {
        $this->guardWrite($config, 'lock', $domain, timeouts: false);

        return new RegistrarResult(message: $locked ? 'Domain locked.' : 'Domain unlocked.');
    }

    public function setIdProtection(RegistrarConfig $config, DomainRef $domain, bool $enabled): RegistrarResult
    {
        $this->guardWrite($config, 'id_protection', $domain, timeouts: false);

        return new RegistrarResult(message: $enabled ? 'ID protection enabled.' : 'ID protection disabled.');
    }

    /**
     * The demo registrar keeps no contacts of its own; the host's copy is the only one.
     */
    public function getContacts(RegistrarConfig $config, DomainRef $domain): ContactSet
    {
        $this->log('contacts', ['domain' => $domain->name]);

        return new ContactSet;
    }

    public function setContacts(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): RegistrarResult
    {
        $this->guardWrite($config, 'contacts', $domain, timeouts: false);

        return new RegistrarResult(message: 'Contacts updated.');
    }

    public function getEppCode(RegistrarConfig $config, DomainRef $domain): EppCodeResult
    {
        $this->log('epp', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'email')) {
            return new EppCodeResult(emailed: true);
        }

        return new EppCodeResult(code: strtoupper(substr(hash('sha256', 'epp:'.$domain->name), 0, 4)).'-'.substr(hash('sha256', $domain->name), 0, 8));
    }

    public function sync(RegistrarConfig $config, DomainRef $domain): SyncResult
    {
        $this->log('sync', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'gone')) {
            return new SyncResult(active: false, transferredAway: true, expiryDate: $domain->expiryDate);
        }

        return new SyncResult(active: true, expiryDate: $domain->expiryDate);
    }

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
    {
        $this->log('transfer_sync', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'transferfail')) {
            return new TransferSyncResult(completed: false, failed: true, reason: 'The losing registrar rejected the transfer: invalid EPP code.');
        }

        // No invented expiry: without one the host dates the transfer from the company calendar.
        return new TransferSyncResult(completed: true, expiryDate: $domain->expiryDate?->addYearsNoOverflow(1));
    }

    public function lookupOrder(RegistrarConfig $config, DomainRef $domain, string $orderId): LinkedOrder
    {
        $this->log('link_order', ['domain' => $domain->name, 'order_id' => $orderId]);

        if (str_contains($domain->sld, 'taken')) {
            throw new RegistrarException("Order {$orderId} is for another name, not {$domain->name}. Nothing was linked.");
        }

        return new LinkedOrder(['order_id' => (int) $orderId], 'active', $domain->expiryDate);
    }

    public function findSubmission(RegistrarConfig $config, DomainRef $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        $this->log('reconcile', ['domain' => $domain->name, 'command' => $command]);

        return match (true) {
            $command === 'renew' => new SubmissionLookup(ReconcileStatus::Executed),
            str_contains($domain->sld, 'taken') => new SubmissionLookup(ReconcileStatus::Conflict, message: 'The name is registered in another account.'),
            $command === 'register' && str_contains($domain->sld, 'timeout') => new SubmissionLookup(ReconcileStatus::Executed),
            str_contains($domain->sld, 'timeout') || str_contains($domain->sld, 'slow') => new SubmissionLookup(ReconcileStatus::InProgress),
            default => new SubmissionLookup(ReconcileStatus::NotExecuted),
        };
    }

    public function testConnection(RegistrarConfig $config): ConnectionCheck
    {
        return $config->setting('api_key') === 'invalid-key'
            ? new ConnectionCheck(false, 'Authentication failed: invalid API key.')
            : new ConnectionCheck(true, 'Connected to the log registrar.');
    }

    private function availabilityOf(string $sld, string $tld): AvailabilityResult
    {
        $domain = $sld.$tld;

        return match (true) {
            str_contains($sld, 'taken') => new AvailabilityResult($domain, 'registered'),
            str_contains($sld, 'reserved') => new AvailabilityResult($domain, 'reserved'),
            str_contains($sld, 'premium') => new AvailabilityResult($domain, 'available', true, 50000, 50000),
            default => new AvailabilityResult($domain, 'available'),
        };
    }

    /**
     * @throws RegistrarException
     */
    private function guardWrite(RegistrarConfig $config, string $command, DomainRef $domain, bool $timeouts = true): void
    {
        $this->log($command, ['domain' => $domain->name]);
        $simulate = $config->setting('simulate', 'none');

        if ($simulate === 'error' || str_contains($domain->sld, 'fail') && ! str_contains($domain->sld, 'transferfail')) {
            throw new RegistrarException("The registrar rejected the {$command} request for {$domain->name}.");
        }

        if ($simulate === 'timeout' || ($timeouts && str_contains($domain->sld, 'timeout'))) {
            throw new RegistrarTimeout;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $command, array $context): void
    {
        Log::info("[log registrar] {$command}", $context);
    }
}
