<?php

namespace App\Modules\Domains\Drivers\Registrars;

use App\Modules\Domains\Drivers\Registrars\Data\AvailabilityResult;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\EppCodeResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Data\TransferSyncResult;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Support\DomainName;
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
 * "slow" one as in progress, a renewal as executed and anything else as absent.
 * The "simulate" setting forces every write command to fail ("error") or time out ("timeout").
 */
class LogRegistrar implements Registrar
{
    private string $simulate = 'none';

    private ?string $apiKey = null;

    public function name(): string
    {
        return 'Log (demo)';
    }

    public function capabilities(): array
    {
        return ['availability', 'suggestions', 'register', 'transfer', 'renew', 'restore', 'info', 'nameservers', 'lock', 'epp', 'contacts', 'id_protection', 'sync', 'transfer_sync', 'reconcile'];
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'help' => 'Any value works; "invalid" fails the connection test.'],
            ['key' => 'simulate', 'label' => 'Simulate failures', 'type' => 'select', 'options' => ['none' => 'Never fail', 'error' => 'Reject every command', 'timeout' => 'Time out every command']],
        ];
    }

    public function configure(array $settings, bool $testMode): void
    {
        $this->simulate = is_string($settings['simulate'] ?? null) ? $settings['simulate'] : 'none';
        $this->apiKey = is_string($settings['api_key'] ?? null) ? $settings['api_key'] : null;
    }

    public function checkAvailability(string $sld, array $tlds): array
    {
        $this->log('availability', ['sld' => $sld, 'tlds' => $tlds]);

        return array_map(fn (string $tld): AvailabilityResult => $this->availabilityOf($sld, $tld), $tlds);
    }

    public function suggest(string $term, array $tlds, int $limit): array
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

    public function register(RegistrationRequest $request): RegistrarResult
    {
        $this->guardWrite('register', $request->domain);

        if (str_contains($request->domain->sld, 'slow')) {
            return new RegistrarResult('pending', message: 'Registration accepted; awaiting registry confirmation.');
        }

        return new RegistrarResult('completed', CarbonImmutable::today()->addYearsNoOverflow($request->years), 'Domain registered.');
    }

    public function transfer(RegistrationRequest $request): RegistrarResult
    {
        $this->guardWrite('transfer', $request->domain);

        return new RegistrarResult('pending', message: 'Transfer requested; awaiting approval by the losing registrar.');
    }

    public function renew(Domain $domain, int $years, bool $restore = false): RegistrarResult
    {
        $this->guardWrite($restore ? 'restore' : 'renew', $domain, timeouts: false);
        $base = $domain->expiry_date ?? CarbonImmutable::today();

        return new RegistrarResult('completed', $base->addYearsNoOverflow($years), $restore ? 'Domain restored and renewed.' : 'Domain renewed.');
    }

    public function info(Domain $domain): DomainInfo
    {
        $this->log('info', ['domain' => $domain->name]);

        $status = match ($domain->status) {
            DomainStatus::Pending => str_contains($domain->sld, 'timeout') ? 'active' : 'not_found',
            DomainStatus::PendingRegistration, DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption => 'active',
            DomainStatus::PendingTransfer => 'pending',
            DomainStatus::Expired => 'expired',
            DomainStatus::TransferredAway => 'transferred_away',
            DomainStatus::Cancelled, DomainStatus::Fraud => 'cancelled',
        };

        $expiry = $domain->expiry_date ?? ($status === 'active' ? CarbonImmutable::today()->addYearsNoOverflow($domain->period_years) : null);

        return new DomainInfo(
            status: $status,
            nameservers: $domain->nameservers ?? [],
            locked: $domain->registrar_lock ?? false,
            expiryDate: $expiry,
            idProtection: $domain->id_protection,
        );
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        $this->guardWrite('nameservers', $domain, timeouts: false);

        return new RegistrarResult(message: 'Nameservers updated.');
    }

    public function setLock(Domain $domain, bool $locked): RegistrarResult
    {
        $this->guardWrite('lock', $domain, timeouts: false);

        return new RegistrarResult(message: $locked ? 'Domain locked.' : 'Domain unlocked.');
    }

    public function setIdProtection(Domain $domain, bool $enabled): RegistrarResult
    {
        $this->guardWrite('id_protection', $domain, timeouts: false);

        return new RegistrarResult(message: $enabled ? 'ID protection enabled.' : 'ID protection disabled.');
    }

    public function getContacts(Domain $domain): ContactSet
    {
        $this->log('contacts', ['domain' => $domain->name]);
        $contacts = [];

        foreach ($domain->contacts as $contact) {
            $contacts[$contact->role] = $contact->only(['first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country']);
        }

        return new ContactSet($contacts);
    }

    public function setContacts(Domain $domain, ContactSet $contacts): RegistrarResult
    {
        $this->guardWrite('contacts', $domain, timeouts: false);

        return new RegistrarResult(message: 'Contacts updated.');
    }

    public function getEppCode(Domain $domain): EppCodeResult
    {
        $this->log('epp', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'email')) {
            return new EppCodeResult(emailed: true);
        }

        return new EppCodeResult(code: strtoupper(substr(hash('sha256', 'epp:'.$domain->name), 0, 4)).'-'.substr(hash('sha256', $domain->name), 0, 8));
    }

    public function sync(Domain $domain): SyncResult
    {
        $this->log('sync', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'gone')) {
            return new SyncResult(active: false, transferredAway: true, expiryDate: $domain->expiry_date);
        }

        return new SyncResult(active: true, expiryDate: $domain->expiry_date ?? CarbonImmutable::today()->addYearsNoOverflow($domain->period_years));
    }

    public function transferSync(Domain $domain): TransferSyncResult
    {
        $this->log('transfer_sync', ['domain' => $domain->name]);

        if (str_contains($domain->sld, 'transferfail')) {
            return new TransferSyncResult(completed: false, failed: true, reason: 'The losing registrar rejected the transfer: invalid EPP code.');
        }

        $base = $domain->expiry_date ?? CarbonImmutable::today();

        return new TransferSyncResult(completed: true, expiryDate: $base->addYearsNoOverflow(1));
    }

    public function findSubmission(Domain $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        $this->log('reconcile', ['domain' => $domain->name, 'command' => $command]);

        return match (true) {
            $command === 'renew' => new SubmissionLookup('executed'),
            $command === 'register' && str_contains($domain->sld, 'timeout') => new SubmissionLookup('executed', CarbonImmutable::today()->addYearsNoOverflow($domain->period_years)),
            str_contains($domain->sld, 'timeout') || str_contains($domain->sld, 'slow') => new SubmissionLookup('in_progress'),
            default => new SubmissionLookup('absent'),
        };
    }

    public function testConnection(): array
    {
        return $this->apiKey === 'invalid'
            ? ['ok' => false, 'message' => 'Authentication failed: invalid API key.']
            : ['ok' => true, 'message' => 'Connected to the log registrar.'];
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
    private function guardWrite(string $command, Domain $domain, bool $timeouts = true): void
    {
        $this->log($command, ['domain' => $domain->name]);

        if ($this->simulate === 'error' || str_contains($domain->sld, 'fail') && ! str_contains($domain->sld, 'transferfail')) {
            throw new RegistrarException("The registrar rejected the {$command} request for {$domain->name}.");
        }

        if ($this->simulate === 'timeout' || ($timeouts && str_contains($domain->sld, 'timeout'))) {
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
