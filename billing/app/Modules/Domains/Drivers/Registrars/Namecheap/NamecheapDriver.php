<?php

namespace App\Modules\Domains\Drivers\Registrars\Namecheap;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\RestoresDomains;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
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
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use Carbon\CarbonImmutable;
use SimpleXMLElement;

/**
 * Namecheap registrar driver (registrars.md §4), over its XML API through NamecheapClient.
 *
 * Namecheap has no idempotency key, no order search by our own reference and no way to read an EPP code, so:
 * - no `ProvidesEppCode` capability (the client asks support; staff read the code in the panel);
 * - the host claims a submission before it is sent and settles an unknown outcome through findSubmission(), which
 *   never re-sends. Before a register or transfer is sent, the account is also read: a name already in it is adopted
 *   by register() only when the host holds its domain id (our own earlier answer), and is a conflict for staff
 *   otherwise, so a name in the account can never be taken over by typing it. findSubmission() also adopts a name
 *   created inside the submission's window (a day either side, dates carry no time) when no id can be held yet, after
 *   a lost answer; an older one is a Conflict. "Domain not available" after an unknown outcome is therefore never
 *   counted as a failure without this ownership check;
 * - ownership is decided by a name's presence in `domains.getList`, which lists this account only (`IsOwner` is not
 *   relied on). A name missing from the list is never taken as gone on that alone: `domains.getInfo` confirms every
 *   miss, and only its "not in this account" errors (2016166, 2019166) say so. A list answer without its list or
 *   paging element is unreadable (retryable), never "empty" (registrars.md §6);
 * - a renewal is sent only when the registry's expiry is the same date as OUR stored expiry (Namecheap takes no
 *   expiry parameter, so the driver is the guard). When Namecheap is ahead by a whole term the renewal already ran
 *   and nothing is ordered again;
 * - a registration that is real-time returns no expiry: it is computed from the term, and the next sync replaces it;
 * - premium names stay off (ADR 0009 Amendment 2): availability reports them as premium and the host hides them.
 *   register() also checks the one name and refuses a premium one before creating it (Namecheap is expected to refuse
 *   a create without `IsPremiumDomain` as well, which is unverified). renew() does not check: a premium name already
 *   held is renewed at the price Namecheap applies.
 *
 * A register or transfer chain runs inside a time budget (REGISTER_BUDGET) that fits the host job's timeout, and the
 * client keeps its own request budget below Namecheap's rate limits.
 */
final class NamecheapDriver implements ChecksAvailability, DeclaresTldAttributes, ManagesContacts, ManagesIdProtection, ManagesLock, ManagesNameservers, ReconcilesSubmissions, RegistrarDriver, RestoresDomains, SyncsDomains, TestsRegistrarConnection, TransfersDomains
{
    public const KEY = 'namecheap';

    /** Seconds a register or transfer chain may use in all; the host job's timeout is 180 s. */
    private const REGISTER_BUDGET = 165;

    /** Names per availability call (Namecheap's limit). */
    private const CHECK_BATCH = 50;

    /** Rows per list page and the pages read at most. */
    private const LIST_ROWS = 100;

    private const LIST_PAGES = 3;

    /** Days allowed between our claim and Namecheap's date of creation (dates carry no time of day). */
    private const WINDOW_DAYS = 1;

    /** Transfer status ids that end a transfer without the name moving (registrars.md §4). */
    private const TRANSFER_CANCELLED = [2, 4, 8, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 30, 31, 32, 33, 34, 35, 36, 37, 45, 48, 49, 50, 51, -4];

    private const TRANSFER_COMPLETED = 5;

    /**
     * Extensions Namecheap takes transfers for through the API (registrars.md §4). The `.es` and `.pe` variants are
     * the ones the reference calls "es/pe variants"; check the exact list against the reference before relying on them.
     */
    private const API_TRANSFER_TLDS = ['.com', '.net', '.org', '.info', '.biz', '.us', '.in', '.co', '.me', '.tv', '.cc', '.ca', '.mobi', '.es', '.com.es', '.nom.es', '.org.es', '.pe', '.com.pe', '.net.pe', '.org.pe', '.nom.pe'];

    public function __construct(private NamecheapClient $client, private NamecheapContacts $contacts) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Namecheap';
    }

    public function settingsFields(): array
    {
        return [
            new SettingField('api_user', 'API user', help: 'The Namecheap account that has API access. Used when test mode is off.'),
            new SettingField('api_key', 'API key', FieldType::Secret, help: 'Profile → Tools → API Access in Namecheap. Regenerating it stops the old key at once.'),
            new SettingField('username', 'User name', help: 'Usually the same as the API user. Leave empty to use the API user.'),
            new SettingField('client_ip', 'Client IP', help: 'The IPv4 address this server sends requests from, which must be on the Namecheap API allow-list. Namecheap checks it on every call.'),
            new SettingField('sandbox_api_user', 'Sandbox API user', help: 'Used only in test mode, with a separate sandbox account (sandbox.namecheap.com).'),
            new SettingField('sandbox_api_key', 'Sandbox API key', FieldType::Secret, help: 'Used only in test mode. Live credentials are never sent to the sandbox.'),
            new SettingField('sandbox_username', 'Sandbox user name', help: 'Usually the same as the sandbox API user.'),
            new SettingField('http_proxy', 'Outbound proxy', FieldType::Secret, help: 'Optional, for example http://user:pass@proxy.example:3128, when the API allow-list needs a fixed IP address this server lacks.'),
        ];
    }

    /**
     * Extra details Namecheap's create call takes as plain parameters for some extensions. The set is small on
     * purpose; an extension that is not listed here is sent without any.
     *
     * - `.us`: nexus category and purpose, from the .US registry's nexus policy (values C11/C12/C21 and P1-P5). Only the
     *   categories that need no extra country are offered (C31/C32 do).
     * - `.ca`: legal type, WHOIS display and language, from the CIRA registrant agreement.
     * - `.eu`: two confirmations of the registry's policies.
     * - `.uk`: Namecheap's attributes are optional; none are asked.
     *
     * Unverified: Namecheap's reference could not be read when this was written (HTTP 403), so the parameter names
     * (`RegistrantNexus`, `RegistrantPurpose`, `CIRALegalType`, `CIRAWhoisDisplay`, `CIRALanguage`, `EUAgreeWhoisPolicy`,
     * `EUAgreeDeletePolicy`) and values follow the registry policies and the expected API shape, not a call to a
     * Namecheap account. `.au`, `.es`, `.de` and `.fr` also need details (registrars.md §4) and are left out until
     * their names can be checked. Check these against the sandbox before enabling the extensions.
     */
    public function tldAttributeFields(string $tld): array
    {
        return match (ltrim(strtolower($tld), '.')) {
            'us' => [
                new SettingField('RegistrantPurpose', 'What the domain will be used for', FieldType::Select, required: true, help: 'Choose the use that fits best. The .us registry checks it.', options: ['P1' => 'Business or other for-profit use', 'P2' => 'Non-profit, club, association or religious group', 'P3' => 'Personal use', 'P4' => 'Education', 'P5' => 'Government']),
                new SettingField('RegistrantNexus', 'Who is registering the name', FieldType::Select, required: true, help: 'Only people and organizations with a connection to the United States can register .us names. If that is not you, choose another extension.', options: ['C11' => 'A United States citizen', 'C12' => 'A permanent resident of the United States or its territories', 'C21' => 'An organization formed in the United States (a company, club or government body)']),
            ],
            'ca' => [
                new SettingField('CIRALegalType', 'Who is registering the name', FieldType::Select, required: true, help: 'Only people and organizations with a connection to Canada can register .ca names. Choose the group that describes the owner; if none fits, choose another extension.', options: [
                    'CCT' => 'A Canadian citizen', 'PR' => 'A permanent resident of Canada', 'CCO' => 'A corporation formed in Canada', 'ABO' => 'An Indigenous person or group in Canada', 'LGR' => 'A legal representative of a Canadian citizen or permanent resident',
                    'TRD' => 'A holder of a Canadian trademark registration', 'GOV' => 'The government of Canada, a province or a municipality', 'EDU' => 'A Canadian educational institution', 'ASS' => 'An unincorporated association in Canada', 'HOP' => 'A Canadian hospital',
                    'PRT' => 'A partnership registered in Canada', 'TDM' => 'A Canadian trademark owner', 'TRS' => 'A trust established in Canada', 'PLT' => 'A Canadian political party', 'LAM' => 'A Canadian library, archive or museum', 'INB' => 'An Indian band in Canada', 'MAJ' => 'His Majesty the King in right of Canada',
                ]),
                new SettingField('CIRAWhoisDisplay', 'Show the owner in the public WHOIS', FieldType::Select, required: true, help: 'Organizations are always shown. For an individual, "Private" hides the personal details.', options: ['Private' => 'Private (hide an individual\'s details)', 'Full' => 'Full (show the details)']),
                new SettingField('CIRALanguage', 'Language of the registry\'s notices', FieldType::Select, required: true, options: ['EN' => 'English', 'FR' => 'French']),
            ],
            'eu' => [
                new SettingField('EUAgreeWhoisPolicy', 'I accept the .eu WHOIS policy', FieldType::Boolean, required: true, help: 'Only people and organizations in the European Union, Iceland, Liechtenstein or Norway can register .eu names.'),
                new SettingField('EUAgreeDeletePolicy', 'I accept the .eu deletion policy', FieldType::Boolean, required: true),
            ],
            default => [],
        };
    }

    /**
     * The request's answers as Namecheap parameters. Only keys the driver declared for the extension are sent,
     * whatever the caller passed; a confirmation is `YES`.
     *
     * @return array<string, string>
     */
    private function attributeParams(RegistrationRequest $request): array
    {
        $params = [];

        foreach ($this->tldAttributeFields($request->domain->tld) as $field) {
            $answer = $request->tldAttributes[$field->key] ?? '';

            if ($answer === '' || ($field->type === FieldType::Boolean && $answer !== 'true')) {
                continue;
            }

            $params[$field->key] = $field->type === FieldType::Boolean ? 'YES' : $answer;
        }

        return $params;
    }

    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
    {
        $names = array_map(fn (string $tld): string => strtolower($sld.$tld), $tlds);
        $rows = [];

        foreach (array_chunk($names, self::CHECK_BATCH) as $batch) {
            $answer = $this->client->read($config, 'namecheap.domains.check', ['DomainList' => implode(',', $batch)]);

            foreach ($answer->DomainCheckResult ?? [] as $row) {
                $rows[strtolower((string) $row['Domain'])] = $row;
            }
        }

        $results = [];

        foreach ($names as $name) {
            $row = $rows[$name] ?? null;
            $available = $row !== null && (int) $row['ErrorNo'] === 0 && self::flag($row['Available'] ?? null);
            $status = match (true) {
                $row === null || (int) $row['ErrorNo'] !== 0 => 'unknown',
                $available => 'available',
                default => 'registered',
            };
            $premium = $row !== null && self::flag($row['IsPremiumName'] ?? null);
            $results[] = new AvailabilityResult(
                $name,
                $status,
                isPremium: $premium,
                premiumRegisterCost: $premium ? self::cents($row['PremiumRegistrationPrice'] ?? null) : null,
                premiumRenewCost: $premium ? self::cents($row['PremiumRenewalPrice'] ?? null) : null,
            );
        }

        return $results;
    }

    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        self::refuseUnsupportedName($request->domain);

        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $request): RegistrarResult {
            if (($earlier = $this->accountEntry($config, $request->domain)) !== null) {
                $this->ours($earlier, $request->domain);

                return new RegistrarResult('completed', $earlier['expires'], 'Already in the Namecheap account; not sent again.', remote: ['domain_id' => (int) $earlier['id']]);
            }

            $this->refusePremium($config, $request->domain);

            $answer = $this->client->change($config, 'namecheap.domains.create', [
                'DomainName' => $request->domain->name,
                'Years' => $request->years,
                ...$this->contacts->params($request->contacts),
                ...($request->nameservers === [] ? [] : ['Nameservers' => implode(',', $request->nameservers)]),
                ...($request->idProtection ? ['AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'yes'] : []),
                ...$this->attributeParams($request),
            ]);
            $result = $answer->DomainCreateResult ?? null;

            if (! $result instanceof SimpleXMLElement || ! isset($result['Domain'])) {
                throw new RegistrarTimeout('Namecheap answered without a registration result; the request may have been carried out.');
            }

            $remote = self::remote(['domain_id' => $result['DomainID'] ?? null, 'order_id' => $result['OrderID'] ?? null]);

            return match (true) {
                self::flag($result['Registered'] ?? null) => new RegistrarResult('completed', CarbonImmutable::now('UTC')->startOfDay()->addYears($request->years), 'Registered at Namecheap; the expiry is computed from the term and corrected by the next sync.', remote: $remote),
                self::flag($result['NonRealTimeDomain'] ?? null) => new RegistrarResult('pending', message: 'Namecheap accepted the registration; the registry has not confirmed it yet.', remote: $remote),
                default => throw new RegistrarTimeout('Namecheap neither registered the domain nor queued it; check the account.'),
            };
        });
    }

    /**
     * @throws RegistrarException when the name is premium, or when that could not be told
     */
    private function refusePremium(RegistrarConfig $config, DomainRef $domain): void
    {
        $check = $this->checkAvailability($config, $domain->sld, [$domain->tld])[0] ?? null;

        if ($check === null || $check->status === 'unknown') {
            throw new RegistrarException('Namecheap did not say whether '.$domain->name.' is a premium name, so it was not registered; try again.', retryable: true);
        }

        if ($check->isPremium) {
            throw new RegistrarException($domain->name.' is a premium name, which this driver does not register; nothing was sent.');
        }
    }

    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        $domain = $request->domain;
        self::refuseUnsupportedName($domain);

        if (! in_array($domain->tld, self::API_TRANSFER_TLDS, true)) {
            throw new RegistrarException('Namecheap does not take transfers of '.$domain->tld.' domains through its API; transfer it in the Namecheap panel instead.');
        }

        $code = (string) $request->eppCode;

        if ($code === '') {
            throw new RegistrarException('Namecheap needs the transfer (EPP) code from the current registrar.');
        }

        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $domain, $code): RegistrarResult {
            if ($this->accountEntry($config, $domain) !== null) {
                throw new RegistrarException($domain->name.' is already in the Namecheap account; nothing was sent.');
            }

            foreach ($this->transferRows($config, $domain) as $row) {
                if (self::transferState((int) $row['StatusID']) !== 'in_progress') {
                    continue;
                }

                // Our own transfer (the host holds its id) is not ordered twice; any other is a conflict for staff.
                if ((string) ($domain->remote['transfer_id'] ?? '') !== (string) $row['ID']) {
                    throw new RegistrarException('A transfer of '.$domain->name.' is already in progress in the Namecheap account but was not started by this submission; link it by hand. Nothing was sent.');
                }

                return new RegistrarResult('pending', message: 'The transfer is already in the Namecheap account; not sent again.', remote: self::remote(['transfer_id' => $row['ID'], 'order_id' => $row['OrderID'] ?? null]));
            }

            $answer = $this->client->change($config, 'namecheap.domains.transfer.create', [
                'DomainName' => $domain->name,
                'Years' => 1,
                'EPPCode' => preg_match('/^[A-Za-z0-9]+$/', $code) === 1 ? $code : 'base64:'.base64_encode($code),
            ]);
            $result = $answer->DomainTransferCreateResult ?? null;

            if (! $result instanceof SimpleXMLElement || ! self::flag($result['Transfer'] ?? null)) {
                throw new RegistrarTimeout('Namecheap did not confirm the transfer order; the request may have been carried out.');
            }

            return new RegistrarResult('pending', message: 'Transfer requested at Namecheap.', remote: self::remote(['transfer_id' => $result['TransferID'] ?? null, 'order_id' => $result['OrderID'] ?? null]));
        });
    }

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
    {
        $id = $domain->remote['transfer_id'] ?? null;

        if ($id === null || $id === '') {
            throw new RegistrarException('No Namecheap transfer is linked to '.$domain->name.'; nothing was read. Link the transfer by hand.');
        }

        $status = $this->client->read($config, 'namecheap.domains.transfer.getStatus', ['TransferID' => (string) $id])->DomainTransferGetStatusResult ?? null;

        if (! $status instanceof SimpleXMLElement || ! isset($status['StatusID'])) {
            throw new RegistrarException('Namecheap returned no status for the transfer of '.$domain->name.'.', retryable: true);
        }

        $remote = ['transfer_id' => (int) $id];

        return match (self::transferState((int) $status['StatusID'])) {
            'completed' => new TransferSyncResult(true, expiryDate: $this->optional(fn (): ?CarbonImmutable => self::date($this->details($config, $domain)['ExpiredDate'] ?? null)), remote: $remote),
            'cancelled' => new TransferSyncResult(false, true, 'Namecheap ended the transfer: '.mb_substr(trim((string) $status['Status']), 0, 200).'.', remote: $remote),
            default => new TransferSyncResult(false, remote: $remote),
        };
    }

    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $domain, $years): RegistrarResult {
            try {
                $details = $this->details($config, $domain);
            } catch (NamecheapRefusal $refusal) {
                throw $refusal->isNotInAccount() ? new RegistrarException($domain->name.' is not in the Namecheap account; nothing was sent.') : $refusal;
            }

            $theirs = self::date($details['ExpiredDate']);
            $ours = $domain->expiryDate?->utc()->startOfDay();
            $remote = self::remote(['domain_id' => $details['ID']]);

            if ($details['Status'] === 'Expired') {
                throw new RegistrarException($domain->name.' has expired at Namecheap; restore it instead of renewing. Nothing was sent.');
            }

            if ($ours === null || $theirs === null) {
                throw new RegistrarException('The expiry of '.$domain->name.' is unknown here or at Namecheap; nothing was sent. Check the domain at Namecheap.');
            }

            if ($theirs->gt($ours->addDay())) {
                // Ahead by a whole renewal term (within a day) means the renewal already ran there and an answer was
                // lost: take its date and order nothing, so the term is never bought twice. Ahead by anything else
                // is not a renewal we can account for: nothing is sent, staff check.
                if ($theirs->lt($ours->addYears($years)->subDay())) {
                    throw new RegistrarException('Namecheap\'s expiry for '.$domain->name.' ('.$theirs->toDateString().') is ahead of ours ('.$ours->toDateString().') by less than the '.$years.'-year term; nothing was sent. Check the domain at Namecheap.');
                }

                return new RegistrarResult('completed', $theirs, 'Namecheap had already renewed '.$domain->name.' to '.$theirs->toDateString().'; our expiry was updated from it and nothing was ordered again.', remote: $remote);
            }

            if ($theirs->lt($ours->subDay())) {
                throw new RegistrarException('Namecheap\'s expiry for '.$domain->name.' ('.$theirs->toDateString().') is before ours ('.$ours->toDateString().'); nothing was sent. Check the domain at Namecheap.');
            }

            $result = $this->client->change($config, 'namecheap.domains.renew', ['DomainName' => $domain->name, 'Years' => $years])->DomainRenewResult ?? null;

            if (! $result instanceof SimpleXMLElement || ! self::flag($result['Renew'] ?? null)) {
                throw new RegistrarTimeout('Namecheap did not confirm the renewal; the request may have been carried out.');
            }

            $expiry = self::date($result->DomainDetails->ExpiredDate ?? null)
                ?? $this->optional(fn (): ?CarbonImmutable => self::date($this->details($config, $domain)['ExpiredDate'] ?? null));

            return new RegistrarResult('completed', $expiry, remote: $remote);
        });
    }

    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        $result = $this->client->change($config, 'namecheap.domains.reactivate', ['DomainName' => $domain->name, 'YearsToAdd' => $years])->DomainReactivateResult ?? null;

        if (! $result instanceof SimpleXMLElement || ! self::flag($result['IsSuccess'] ?? null)) {
            throw new RegistrarException('Namecheap did not restore '.$domain->name.'; check the domain at Namecheap.');
        }

        return new RegistrarResult('completed', $this->optional(fn (): ?CarbonImmutable => self::date($this->details($config, $domain)['ExpiredDate'] ?? null)));
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
        try {
            $details = $this->details($config, $domain);
        } catch (NamecheapRefusal $refusal) {
            if ($refusal->isNotInAccount()) {
                return new DomainInfo('not_found');
            }

            throw $refusal;
        }

        [$sld, $tld] = self::split($domain);
        $nameservers = $this->optional(function () use ($config, $sld, $tld): array {
            $list = $this->client->read($config, 'namecheap.domains.dns.getList', ['SLD' => $sld, 'TLD' => $tld])->DomainDNSGetListResult ?? null;
            $names = [];

            foreach ($list->Nameserver ?? [] as $nameserver) {
                $names[] = strtolower(trim((string) $nameserver));
            }

            return $names;
        }) ?? [];
        $locked = $this->optional(function () use ($config, $domain): ?bool {
            $lock = $this->client->read($config, 'namecheap.domains.getRegistrarLock', ['DomainName' => $domain->name])->DomainGetRegistrarLockResult ?? null;

            return $lock instanceof SimpleXMLElement && isset($lock['RegistrarLockStatus']) ? self::flag($lock['RegistrarLockStatus']) : null;
        });

        return new DomainInfo(
            status: ($details['Status'] ?? '') === 'Expired' ? 'expired' : 'active',
            nameservers: $nameservers,
            locked: $locked,
            expiryDate: self::date($details['ExpiredDate'] ?? null),
            idProtection: ($details['WhoisguardEnabled'] ?? null) === null ? null : strtolower((string) $details['WhoisguardEnabled']) === 'true',
            remote: self::remote(['domain_id' => $details['ID'] ?? null]),
        );
    }

    /**
     * One list read (registrars.md §6): expiry, expired flag and lock for the domain without the three calls info()
     * makes. A name missing from the list is confirmed with getInfo before it is judged gone.
     */
    public function sync(RegistrarConfig $config, DomainRef $domain): SyncResult
    {
        $entry = $this->accountEntry($config, $domain);

        if ($entry !== null) {
            return new SyncResult(! $entry['expired'], expiryDate: $entry['expires'], remote: ['domain_id' => (int) $entry['id']]);
        }

        // Not in the account. Only an active domain is judged by the registry.
        if ($domain->status !== 'active') {
            return new SyncResult(false);
        }

        $status = $this->checkAvailability($config, $domain->sld, [$domain->tld])[0]->status ?? 'unknown';

        return match ($status) {
            'available' => new SyncResult(false, cancelled: true),
            'registered' => new SyncResult(false, transferredAway: true),
            default => throw new RegistrarException('Namecheap has no record of '.$domain->name.' in the account and the registry did not answer; the domain was left unchanged.', retryable: true),
        };
    }

    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult
    {
        [$sld, $tld] = self::split($domain);
        $result = $this->client->change($config, 'namecheap.domains.dns.setCustom', ['SLD' => $sld, 'TLD' => $tld, 'Nameservers' => implode(',', $nameservers)])->DomainDNSSetCustomResult ?? null;

        return $this->confirmed($result, 'Update', 'The name servers were not changed at Namecheap.');
    }

    public function setLock(RegistrarConfig $config, DomainRef $domain, bool $locked): RegistrarResult
    {
        $result = $this->client->change($config, 'namecheap.domains.setRegistrarLock', ['DomainName' => $domain->name, 'LockAction' => $locked ? 'LOCK' : 'UNLOCK'])->DomainSetRegistrarLockResult ?? null;

        return $this->confirmed($result, 'IsSuccess', 'The registrar lock was not changed at Namecheap.');
    }

    /**
     * Privacy is switched by the privacy service's own id, which getInfo returns. Turning it on also needs the address
     * mail is forwarded to: the registrant's email as Namecheap holds it.
     */
    public function setIdProtection(RegistrarConfig $config, DomainRef $domain, bool $enabled): RegistrarResult
    {
        $details = $this->details($config, $domain);
        $id = (string) ($details['WhoisguardID'] ?? '');

        if ($id === '') {
            throw new RegistrarException('Namecheap has no privacy service for '.$domain->name.'; ID protection cannot be changed.');
        }

        $params = ['WhoisguardID' => $id];

        if ($enabled) {
            $email = trim((string) ($this->getContacts($config, $domain)->role('registrant')['email'] ?? ''));

            if ($email === '') {
                throw new RegistrarException('The registrant of '.$domain->name.' has no email address at Namecheap to forward privacy mail to.');
            }

            $params['ForwardedToEmail'] = $email;
        }

        $answer = $this->client->change($config, $enabled ? 'namecheap.whoisguard.enable' : 'namecheap.whoisguard.disable', $params);

        return $this->confirmed($enabled ? ($answer->WhoisguardEnableResult ?? null) : ($answer->WhoisguardDisableResult ?? null), 'IsSuccess', 'ID protection was not changed at Namecheap.');
    }

    public function getContacts(RegistrarConfig $config, DomainRef $domain): ContactSet
    {
        $result = $this->client->read($config, 'namecheap.domains.getContacts', ['DomainName' => $domain->name])->DomainContactsResult ?? null;

        if (! $result instanceof SimpleXMLElement) {
            throw new RegistrarException('Namecheap returned no contacts for '.$domain->name.'.', retryable: true);
        }

        return $this->contacts->contactSet($result);
    }

    public function setContacts(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): RegistrarResult
    {
        $params = $this->contacts->params($contacts);
        $before = $this->getContacts($config, $domain)->role('registrant');
        $result = $this->client->change($config, 'namecheap.domains.setContacts', ['DomainName' => $domain->name, ...$params])->DomainSetContactResult ?? null;
        $done = $this->confirmed($result, 'IsSuccess', 'The contacts were not changed at Namecheap.');

        // A new registrant starts the 60-day transfer lock of a generic extension; there is no way to opt out. The
        // two-letter extensions are country codes with their own rules, so only the generic ones are flagged.
        $registrant = $contacts->role('registrant') ?? [];
        $changed = $before === null || array_filter(['first_name', 'last_name', 'company', 'email'], fn (string $field): bool => strcasecmp(trim((string) ($before[$field] ?? '')), trim((string) ($registrant[$field] ?? ''))) !== 0) !== [];
        $generic = strlen(self::lastLabel($domain->tld)) > 2;

        return $changed && $generic
            ? new RegistrarResult($done->status, message: $done->message, meta: ['transfer_lock_until' => CarbonImmutable::today()->addDays(60)->toDateString()])
            : $done;
    }

    public function findSubmission(RegistrarConfig $config, DomainRef $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        if ($command === 'renew') {
            try {
                $details = $this->details($config, $domain);
            } catch (NamecheapRefusal $refusal) {
                if (! $refusal->isNotInAccount()) {
                    throw $refusal;
                }

                $details = null;
            }

            $expiry = $details === null ? null : self::date($details['ExpiredDate']);

            if ($expiry === null || $domain->expiryDate === null) {
                return new SubmissionLookup(ReconcileStatus::Unknown, message: 'The domain or its expiry could not be read from the Namecheap account.');
            }

            // Dates, not instants: ours is a date, and Namecheap's carries no time of day. Executed only when it moved on.
            $renewed = $expiry->toDateString() > $domain->expiryDate->utc()->addDay()->toDateString();

            return new SubmissionLookup($renewed ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $renewed ? $expiry : null, self::remote(['domain_id' => $details['ID']]));
        }

        if ($command === 'transfer') {
            return $this->findTransfer($config, $domain, $submittedAt);
        }

        // Ours: the id the host holds, or a name created in the account since the submission began.
        $entry = $this->accountEntry($config, $domain);

        if ($entry !== null) {
            $held = (string) ($domain->remote['domain_id'] ?? '') === $entry['id'];

            if (! $held && ! self::inWindow($entry['created'], $submittedAt)) {
                return new SubmissionLookup(ReconcileStatus::Conflict, message: 'The name is in the Namecheap account, but it was created before this submission; link it by hand.');
            }

            return new SubmissionLookup(ReconcileStatus::Executed, $entry['expires'], ['domain_id' => (int) $entry['id']]);
        }

        return match ($this->checkAvailability($config, $domain->sld, [$domain->tld])[0]->status ?? 'unknown') {
            'available' => new SubmissionLookup(ReconcileStatus::NotExecuted),
            'registered' => new SubmissionLookup(ReconcileStatus::Conflict, message: 'The name is registered, but not in the Namecheap account.'),
            default => new SubmissionLookup(ReconcileStatus::Unknown, message: 'The registry did not answer the availability check.'),
        };
    }

    public function testConnection(RegistrarConfig $config): ConnectionCheck
    {
        try {
            $this->client->read($config, 'namecheap.domains.getTldList');
        } catch (RegistrarException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        return new ConnectionCheck(true, $config->testMode ? 'Connected to the Namecheap sandbox.' : 'Connected to the Namecheap account.');
    }

    /**
     * A transfer of the name that this submission started: the transfer id the host holds, or one dated within the
     * submission's window. A name that is already in the account counts as done even when the transfer row is gone.
     */
    private function findTransfer(RegistrarConfig $config, DomainRef $domain, CarbonImmutable $submittedAt): SubmissionLookup
    {
        $held = (string) ($domain->remote['transfer_id'] ?? '');
        $rows = array_values(array_filter($this->transferRows($config, $domain), fn (SimpleXMLElement $row): bool => ($held !== '' && (string) $row['ID'] === $held) || ($held === '' && self::inWindow(self::date($row['TransferDate'] ?? null), $submittedAt))));
        usort($rows, fn (SimpleXMLElement $a, SimpleXMLElement $b): int => (int) $b['ID'] <=> (int) $a['ID']);

        if ($rows !== []) {
            $row = $rows[0];
            $remote = self::remote(['transfer_id' => $row['ID'], 'order_id' => $row['OrderID'] ?? null]);

            return match (self::transferState((int) $row['StatusID'])) {
                'completed' => new SubmissionLookup(ReconcileStatus::Executed, remote: $remote),
                'cancelled' => new SubmissionLookup(ReconcileStatus::NotExecuted, remote: $remote, message: 'Namecheap ended the transfer: '.mb_substr(trim((string) $row['Status']), 0, 200).'.'),
                default => new SubmissionLookup(ReconcileStatus::InProgress, remote: $remote),
            };
        }

        if (($entry = $this->accountEntry($config, $domain)) !== null) {
            return new SubmissionLookup(ReconcileStatus::Executed, $entry['expires'], ['domain_id' => (int) $entry['id']]);
        }

        return match ($this->checkAvailability($config, $domain->sld, [$domain->tld])[0]->status ?? 'unknown') {
            'unknown' => new SubmissionLookup(ReconcileStatus::Unknown, message: 'The registry did not answer the availability check.'),
            default => new SubmissionLookup(ReconcileStatus::NotExecuted, message: 'No transfer order for this name is in the Namecheap account.'),
        };
    }

    /**
     * The name's entry in OUR account, or null. Presence in `domains.getList` (which lists this account only) is the
     * proof that it is ours; `IsOwner` is not read. The list is searched by the name and the exact name is picked from
     * the rows. A miss is never proof of absence (registrars.md §6): `domains.getInfo` confirms it, and only its
     * "not in this account" errors return null. A list answer without its list or paging element is unreadable.
     *
     * @return array{id: string, created: CarbonImmutable|null, expires: CarbonImmutable|null, expired: bool}|null
     *
     * @throws RegistrarException retryable, when the list answer cannot be read
     */
    private function accountEntry(RegistrarConfig $config, DomainRef $domain): ?array
    {
        for ($page = 1; $page <= self::LIST_PAGES; $page++) {
            $list = $this->client->read($config, 'namecheap.domains.getList', ['SearchTerm' => $domain->name, 'PageSize' => self::LIST_ROWS, 'Page' => $page]);

            if (! isset($list->DomainGetListResult) || ! isset($list->Paging->TotalItems)) {
                throw new RegistrarException('Namecheap\'s domain list for '.$domain->name.' could not be read; nothing was changed.', retryable: true);
            }

            foreach ($list->DomainGetListResult->Domain ?? [] as $row) {
                if (strtolower((string) $row['Name']) === $domain->name) {
                    return [
                        'id' => (string) $row['ID'], 'created' => self::date($row['Created'] ?? null), 'expires' => self::date($row['Expires'] ?? null), 'expired' => self::flag($row['IsExpired'] ?? null),
                    ];
                }
            }

            if ((int) $list->Paging->TotalItems <= $page * self::LIST_ROWS) {
                break;
            }
        }

        try {
            $details = $this->details($config, $domain);
        } catch (NamecheapRefusal $refusal) {
            if ($refusal->isNotInAccount()) {
                return null;
            }

            throw $refusal;
        }

        return ['id' => (string) $details['ID'], 'created' => self::date($details['CreatedDate'] ?? null), 'expires' => self::date($details['ExpiredDate'] ?? null), 'expired' => ($details['Status'] ?? '') === 'Expired'];
    }

    /**
     * @return list<SimpleXMLElement> the account's transfers of exactly this name
     */
    private function transferRows(RegistrarConfig $config, DomainRef $domain): array
    {
        $list = $this->client->read($config, 'namecheap.domains.transfer.getList', ['SearchTerm' => $domain->name]);
        $rows = [];

        foreach ($list->TransferGetListResult->Transfer ?? [] as $row) {
            if (strtolower((string) $row['DomainName']) === $domain->name) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * What `domains.getInfo` says about a name that is in the account.
     *
     * @return array<string, string> Status, ID, ExpiredDate, CreatedDate, WhoisguardID, WhoisguardEnabled
     *
     * @throws NamecheapRefusal when the name is not in the account (isNotInAccount())
     */
    private function details(RegistrarConfig $config, DomainRef $domain): array
    {
        $result = $this->client->read($config, 'namecheap.domains.getInfo', ['DomainName' => $domain->name])->DomainGetInfoResult ?? null;

        if (! $result instanceof SimpleXMLElement || ! isset($result['ID'])) {
            throw new RegistrarException('Namecheap returned no details for '.$domain->name.'.', retryable: true);
        }

        return [
            'Status' => (string) $result['Status'], 'ID' => (string) $result['ID'],
            'ExpiredDate' => (string) $result->DomainDetails->ExpiredDate, 'CreatedDate' => (string) $result->DomainDetails->CreatedDate,
            'WhoisguardID' => (string) $result->Whoisguard->ID, 'WhoisguardEnabled' => strtolower((string) $result->Whoisguard['Enabled']),
        ];
    }

    /**
     * An entry found by searching for the name is adopted only when the host already holds its domain id.
     *
     * @param  array{id: string}  $entry
     *
     * @throws RegistrarException
     */
    private function ours(array $entry, DomainRef $domain): void
    {
        if ((string) ($domain->remote['domain_id'] ?? '') !== $entry['id']) {
            throw new RegistrarException($domain->name.' is already in the Namecheap account but was not registered by this submission; link it by hand. Nothing was sent.');
        }
    }

    private function confirmed(mixed $result, string $flag, string $failure): RegistrarResult
    {
        if (! $result instanceof SimpleXMLElement || ! self::flag($result[$flag] ?? null)) {
            throw new RegistrarException($failure);
        }

        return new RegistrarResult('completed');
    }

    /**
     * A read that adds detail to an answer: a failure to get it leaves the detail out instead of failing the call.
     * A refusal is swallowed; a doubt about the connection (retryable) is not.
     *
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T|null
     */
    private function optional(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (RegistrarException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @return array{0: string, 1: string} the label(s) before the extension and the extension without its dot
     */
    private static function split(DomainRef $domain): array
    {
        return [$domain->sld, ltrim($domain->tld, '.')];
    }

    private static function lastLabel(string $tld): string
    {
        $labels = explode('.', trim($tld, '.'));

        return strtolower((string) end($labels));
    }

    /**
     * @return 'completed'|'cancelled'|'in_progress'
     */
    private static function transferState(int $statusId): string
    {
        return match (true) {
            $statusId === self::TRANSFER_COMPLETED => 'completed',
            in_array($statusId, self::TRANSFER_CANCELLED, true) => 'cancelled',
            default => 'in_progress',
        };
    }

    /**
     * Whether a date of creation (a day, no time) falls in the window of the submission that began at $since: from the
     * day before to the day after the register budget ended.
     */
    private static function inWindow(?CarbonImmutable $created, CarbonImmutable $since): bool
    {
        return $created !== null
            && $created->gte($since->utc()->startOfDay()->subDays(self::WINDOW_DAYS))
            && $created->lte($since->utc()->addSeconds(self::REGISTER_BUDGET)->startOfDay()->addDays(self::WINDOW_DAYS));
    }

    /**
     * @param  array<string, mixed>  $ids  name => value from the answer
     * @return array<string, int>
     */
    private static function remote(array $ids): array
    {
        $remote = [];

        foreach ($ids as $name => $value) {
            if (is_scalar($value) || $value instanceof SimpleXMLElement) {
                $text = trim((string) $value);

                if ($text !== '' && ctype_digit($text)) {
                    $remote[$name] = (int) $text;
                }
            }
        }

        return $remote;
    }

    /** Namecheap's booleans are the strings `true` and `false` (also `True`/`yes` in some answers). */
    private static function flag(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', 'yes', '1'], true);
    }

    /** USD decimals ("120.0000") as cents. */
    private static function cents(mixed $value): ?int
    {
        return is_numeric((string) $value) ? (int) round(((float) (string) $value) * 100) : null;
    }

    /** Namecheap's `MM/DD/YYYY` as a UTC day. */
    private static function date(mixed $value): ?CarbonImmutable
    {
        $text = trim((string) $value);

        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $text) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!m/d/Y', $text, 'UTC');
    }

    /**
     * `.np` names are registered by the client at the registry (registrars.md §9), and internationalised names need a
     * language code this driver does not collect yet; both are refused before any call.
     *
     * @throws RegistrarException
     */
    private static function refuseUnsupportedName(DomainRef $domain): void
    {
        if (self::lastLabel($domain->tld) === 'np') {
            throw new RegistrarException('.np domains are registered by the client at the registry, not through Namecheap.');
        }

        if (str_contains($domain->name, 'xn--')) {
            throw new RegistrarException('Namecheap needs a language code for internationalised domain names, which this driver does not send yet; register '.$domain->name.' in the Namecheap panel.');
        }
    }
}
