<?php

namespace App\Modules\Domains\Drivers\Registrars\ResellerClub;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
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
use App\Data\Domains\Registrar\EppCodeResult;
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

/**
 * ResellerClub (LogicBoxes) registrar driver (registrars.md §3), over its HTTP API through ResellerClubClient.
 *
 * Orders are placed with `invoice-option=NoInvoice` (the host bills the client; the price comes off the reseller
 * balance) and `auto-renew=false` (the host renews). The order id is kept as `remote.order_id`.
 *
 * Never twice: the host claims a submission before it is sent and settles an unknown outcome through
 * findSubmission(), which never re-sends. Before a register or transfer is sent, the account is also searched, for
 * the client's own customer, and a live order of that name is adopted only when the host already holds its order id;
 * any other order (placed by someone else, or by an attempt we cannot prove is ours) is a conflict for staff, so a
 * name in the reseller account can never be taken over by typing it, whatever code is entered. A renewal is sent
 * (with ResellerClub's own expiry epoch as `exp-date`, which it requires exactly) only when that expiry is the same
 * date as OUR stored expiry. When ResellerClub is ahead of us the renewal already ran: the call returns its date as
 * the result and orders nothing, so a repeat after a lost renewal never buys a second term.
 *
 * Every order read is tied to the domain: by the order id the host holds, or else by the client's customer.
 * A register or transfer chain runs inside a time budget (REGISTER_BUDGET) that fits the host job's timeout.
 */
final class ResellerClubDriver implements ChecksAvailability, DeclaresTldAttributes, ManagesContacts, ManagesIdProtection, ManagesLock, ManagesNameservers, ProvidesEppCode, ReconcilesSubmissions, RegistrarDriver, RestoresDomains, SyncsDomains, TestsRegistrarConnection, TransfersDomains
{
    public const KEY = 'resellerclub';

    /** Order states that no longer hold the name. */
    private const GONE = ['Deleted', 'Archived'];

    /** Seconds a register or transfer chain may use in all; the host job's timeout is 180 s. */
    private const REGISTER_BUDGET = 165;

    /** Clock slack allowed between our claim time and ResellerClub's order creation time. */
    private const CLOCK_SLACK = 120;

    /** Rows per search page and the pages read at most: bounds one lookup to 250 rows. */
    private const SEARCH_ROWS = 50;

    private const SEARCH_PAGES = 5;

    public function __construct(private ResellerClubClient $client, private ResellerClubContacts $contacts) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'ResellerClub';
    }

    public function settingsFields(): array
    {
        return [
            new SettingField('reseller_id', 'Reseller id', help: 'Shown under Manage Profile in the ResellerClub panel. Used when test mode is off.'),
            new SettingField('api_key', 'API key', FieldType::Secret, help: 'Settings → API in the ResellerClub panel. Regenerating it stops the old key at once.'),
            new SettingField('demo_reseller_id', 'Demo reseller id', help: 'Used only in test mode, with the demo account. The demo account is wiped every day.'),
            new SettingField('demo_api_key', 'Demo API key', FieldType::Secret, help: 'Used only in test mode. Live credentials are never sent to the demo host.'),
            new SettingField('customer_mode', 'Customers at ResellerClub', FieldType::Select, options: ['default_customer' => 'One default customer for every domain', 'per_client' => 'One customer per client'], default: 'default_customer'),
            new SettingField('default_customer_id', 'Default customer id', help: 'The ResellerClub customer that holds every domain when one default customer is chosen.'),
            new SettingField('http_proxy', 'Outbound proxy', FieldType::Secret, help: 'Optional, for example http://user:pass@proxy.example:3128, when the API allow-list needs a fixed IP address this server lacks.'),
        ];
    }

    /**
     * Extra details ResellerClub's register call takes as `attr-name{n}` / `attr-value{n}` pairs for some extensions.
     * The set is small on purpose; an extension that is not listed here is sent without attributes.
     *
     * - `.us`: purpose and nexus category, from the .US registry's nexus policy
     *   (https://support.opensrs.com/support/solutions/articles/201000063592, values P1-P5 and C11/C12/C21). Only
     *   the categories that need no extra validator are offered (C31/C32 also need a nexus country).
     * - `.com.au`: eligibility id, id type, policy reason and warranty, as ResellerClub's `.au` registration takes them.
     *
     * Unverified: ResellerClub's own knowledge base could not be read when this was written, so the attribute names
     * (`purpose`, `category`, `id-type`, `id`, `policyReason`, `isAUWarranty`) and the `.com.au` values follow the
     * registry policies and the expected API shape, not a call to a ResellerClub account. Check them against the
     * demo account before enabling these extensions.
     */
    public function tldAttributeFields(string $tld): array
    {
        return match (ltrim(strtolower($tld), '.')) {
            'us' => [
                new SettingField('purpose', 'What the domain is for', FieldType::Select, required: true, options: ['P1' => 'Business for profit', 'P2' => 'Non-profit, club, association or religious group', 'P3' => 'Personal use', 'P4' => 'Education', 'P5' => 'Government']),
                new SettingField('category', 'Who is registering it', FieldType::Select, required: true, options: ['C11' => 'A citizen of the United States', 'C12' => 'A permanent resident of the United States', 'C21' => 'An organization formed in the United States'], help: 'The .us registry only allows people and organizations with a connection to the United States.'),
            ],
            'com.au' => [
                new SettingField('id-type', 'Type of identification', FieldType::Select, required: true, options: ['ACN' => 'Australian Company Number', 'ABN' => 'Australian Business Number', 'TM' => 'Australian trademark', 'Other' => 'Other']),
                new SettingField('id', 'Identification number', required: true),
                new SettingField('policyReason', 'Why the name suits the registrant', FieldType::Select, required: true, options: ['1' => 'It matches the registrant\'s name, abbreviation or trading name', '2' => 'It is closely and substantially connected to the registrant']),
                new SettingField('isAUWarranty', 'The registrant is eligible for a .com.au name', FieldType::Boolean, required: true),
            ],
            default => [],
        };
    }

    /**
     * The request's attributes as numbered pairs, in the order of the declared fields. Only keys the driver
     * declared for the extension are sent, whatever the caller passed.
     *
     * @return array<string, string>
     */
    private function attributeParams(RegistrationRequest $request): array
    {
        $declared = array_map(fn (SettingField $field): string => $field->key, $this->tldAttributeFields($request->domain->tld));
        $params = [];
        $n = 0;

        foreach ($declared as $key) {
            if (isset($request->tldAttributes[$key]) && $request->tldAttributes[$key] !== '') {
                $n++;
                $params['attr-name'.$n] = $key;
                $params['attr-value'.$n] = $request->tldAttributes[$key];
            }
        }

        return $params;
    }

    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
    {
        $answer = (array) $this->client->get($config, 'domains/available.json', ['domain-name' => [$sld], 'tlds' => array_map(fn (string $tld): string => ltrim($tld, '.'), $tlds)], check: true);
        $results = [];

        foreach ($tlds as $tld) {
            $name = strtolower($sld.$tld);
            $row = is_array($answer[$name] ?? null) ? $answer[$name] : [];
            $status = match ($row['status'] ?? null) {
                'available' => 'available',
                'regthroughus', 'regthroughothers' => 'registered',
                default => 'unknown',
            };
            $results[] = new AvailabilityResult($name, $status, isPremium: isset($row['costHash']));
        }

        return $results;
    }

    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        self::refuseSelfRegistered($request->domain);

        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $request): RegistrarResult {
            if (($earlier = $this->existingOrder($config, $request->domain, bySearch: true)) !== null) {
                return $this->resultFor($this->ours($earlier, $request->domain), 'Already in the ResellerClub account; not sent again.');
            }

            $answer = $this->client->post($config, 'domains/register.json', [
                ...$this->orderParams($config, $request),
                ...$this->attributeParams($request),
                'years' => $request->years,
                'ns' => $request->nameservers,
            ]);

            return $this->actionResult($config, $request->domain, $answer);
        });
    }

    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        self::refuseSelfRegistered($request->domain);
        $code = (string) $request->eppCode;

        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $request, $code): RegistrarResult {
            if (($earlier = $this->existingOrder($config, $request->domain, bySearch: true)) !== null) {
                // Our own transfer order (the host holds its id) that waits for its code: hand the code to that
                // order instead of ordering again. Any other order of the name is a conflict, never adopted.
                $earlier = $this->ours($earlier, $request->domain);
                $message = 'The transfer is already in the ResellerClub account; not sent again.';

                if ($earlier['entity.currentstatus'] === 'InActive' && $code !== '') {
                    $message = $this->submitCode($config, $earlier['orders.orderid'], $code) ?? $message;
                }

                return new RegistrarResult('pending', message: $message, remote: ['order_id' => (int) $earlier['orders.orderid']]);
            }

            $answer = (array) $this->client->post($config, 'domains/transfer.json', [
                ...$this->orderParams($config, $request),
                ...($code === '' ? [] : ['auth-code' => $code]),
                ...($request->nameservers === [] ? [] : ['ns' => $request->nameservers]),
            ]);

            return new RegistrarResult('pending', message: (string) ($answer['actionstatusdesc'] ?? 'Transfer requested at ResellerClub.'), remote: self::orderRemote($answer));
        });
    }

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
    {
        // Only the order whose id the host holds counts: a name's order that nothing proves ours is never completed.
        $order = $this->existingOrder($config, $domain, includeGone: true);

        return match (true) {
            $order === null => new TransferSyncResult(false),
            $order['entity.currentstatus'] === 'Active' => new TransferSyncResult(true, expiryDate: self::epoch($order['orders.endtime'] ?? null), remote: ['order_id' => (int) $order['orders.orderid']]),
            in_array($order['entity.currentstatus'], self::GONE, true) => new TransferSyncResult(false, true, 'The transfer was cancelled at ResellerClub; check the order there for the reason.'),
            default => new TransferSyncResult(false, remote: ['order_id' => (int) $order['orders.orderid']]),
        };
    }

    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        return $this->client->within(self::REGISTER_BUDGET, function () use ($config, $domain, $years): RegistrarResult {
            $details = $this->details($config, $domain, ['OrderDetails']);
            $theirs = self::epoch($details['endtime'] ?? null);

            $ours = $domain->expiryDate?->utc()->startOfDay();

            if ($ours === null || $theirs === null) {
                throw new RegistrarException('The expiry of '.$domain->name.' is unknown here or at ResellerClub; nothing was sent. Check the domain at ResellerClub.');
            }

            if ($theirs->startOfDay()->gt($ours->addDay())) {
                // Ahead by a whole renewal term (within a day) means the renewal already ran there and an answer was
                // lost: take its date and order nothing, so the term is never bought twice. Ahead by anything else
                // (changed at the registrar) is not a renewal we can account for: nothing is sent, staff check.
                if ($theirs->startOfDay()->lt($ours->addYears($years)->subDay())) {
                    throw new RegistrarException('ResellerClub\'s expiry for '.$domain->name.' ('.$theirs->toDateString().') is ahead of ours ('.$ours->toDateString().') by less than the '.$years.'-year term; nothing was sent. Check the domain at ResellerClub.');
                }

                return new RegistrarResult('completed', $theirs, 'ResellerClub had already renewed '.$domain->name.' to '.$theirs->toDateString().'; our expiry was updated from it and nothing was ordered again.', remote: ['order_id' => (int) $details['orderid']]);
            }

            if ($theirs->startOfDay()->lt($ours->subDay())) {
                throw new RegistrarException('ResellerClub\'s expiry for '.$domain->name.' ('.$theirs->toDateString().') is before ours ('.$ours->toDateString().'); nothing was sent. Check the domain at ResellerClub.');
            }

            // The dates agree, so ResellerClub's epoch is our expiry; it also makes ResellerClub refuse a stale repeat.
            $answer = $this->client->post($config, 'domains/renew.json', [
                'order-id' => $details['orderid'], 'years' => $years, 'exp-date' => (string) $details['endtime'],
                'invoice-option' => 'NoInvoice', 'auto-renew' => 'false',
            ]);

            return $this->actionResult($config, $domain, $answer, previousEnd: (string) $details['endtime']);
        });
    }

    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        $answer = $this->client->post($config, 'domains/restore.json', ['order-id' => $this->orderId($config, $domain), 'invoice-option' => 'NoInvoice']);

        return $this->actionResult($config, $domain, $answer);
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
        $this->requireLinkedOrder($domain);
        $order = $this->existingOrder($config, $domain, includeGone: true);

        if ($order === null) {
            return new DomainInfo('not_found');
        }

        $details = $this->details($config, $domain, ['OrderDetails', 'NsDetails', 'DomainStatus']);
        $nameservers = [];

        for ($i = 1; isset($details['ns'.$i]); $i++) {
            $nameservers[] = strtolower((string) $details['ns'.$i]);
        }

        return new DomainInfo(
            status: match ($details['currentstatus'] ?? null) {
                'Active', 'Suspended' => 'active',
                'InActive' => 'pending',
                'Pending Delete Restorable' => 'expired',
                default => 'cancelled',
            },
            nameservers: $nameservers,
            locked: in_array('transferlock', (array) ($details['orderstatus'] ?? []), true),
            expiryDate: self::epoch($details['endtime'] ?? null),
            idProtection: ($details['isprivacyprotected'] ?? 'false') === 'true',
            contactChangePending: in_array('sixtydaylock', (array) ($details['domainstatus'] ?? []), true),
            remote: ['order_id' => (int) $details['orderid']],
        );
    }

    public function sync(RegistrarConfig $config, DomainRef $domain): SyncResult
    {
        $info = $this->info($config, $domain);

        if (! $info->exists()) {
            // The recorded order is gone from the account's search: only an active domain is judged by the registry.
            if ($domain->status !== 'active') {
                return new SyncResult(false);
            }

            $status = $this->checkAvailability($config, $domain->sld, [$domain->tld])[0]->status ?? 'unknown';

            return match ($status) {
                'available' => new SyncResult(false, cancelled: true),
                'registered' => new SyncResult(false, transferredAway: true),
                default => throw new RegistrarException('ResellerClub has no order for '.$domain->name.' and the registry did not answer; the domain was left unchanged.', retryable: true),
            };
        }

        return new SyncResult($info->status === 'active', cancelled: $info->status === 'cancelled', expiryDate: $info->expiryDate, remote: []);
    }

    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult
    {
        return $this->actionResult($config, $domain, $this->client->post($config, 'domains/modify-ns.json', ['order-id' => $this->orderId($config, $domain), 'ns' => $nameservers]), readBack: false);
    }

    public function setLock(RegistrarConfig $config, DomainRef $domain, bool $locked): RegistrarResult
    {
        $path = $locked ? 'domains/enable-theft-protection.json' : 'domains/disable-theft-protection.json';

        return $this->actionResult($config, $domain, $this->client->post($config, $path, ['order-id' => $this->orderId($config, $domain)]), readBack: false);
    }

    public function setIdProtection(RegistrarConfig $config, DomainRef $domain, bool $enabled): RegistrarResult
    {
        return $this->actionResult($config, $domain, $this->client->post($config, 'domains/modify-privacy-protection.json', [
            'order-id' => $this->orderId($config, $domain), 'protect-privacy' => $enabled ? 'true' : 'false', 'reason' => 'Changed at the domain owner\'s request.',
        ]), readBack: false);
    }

    public function getContacts(RegistrarConfig $config, DomainRef $domain): ContactSet
    {
        return $this->contacts->contactSet($config, $this->details($config, $domain, ['ContactIds']));
    }

    public function setContacts(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): RegistrarResult
    {
        $details = $this->details($config, $domain, ['OrderDetails']);
        $ids = $this->contacts->contactIds($config, $domain, $contacts, (string) $details['customerid']);
        $answer = $this->client->post($config, 'domains/modify-contact.json', ['order-id' => $details['orderid'], ...$ids]);
        $after = $this->details($config, $domain, ['DomainStatus']);
        $locked = in_array('sixtydaylock', (array) ($after['domainstatus'] ?? []), true);

        return new RegistrarResult(
            self::status($answer),
            message: (string) (((array) $answer)['actionstatusdesc'] ?? ''),
            meta: $locked ? ['transfer_lock_until' => CarbonImmutable::today()->addDays(60)->toDateString()] : [],
        );
    }

    public function getEppCode(RegistrarConfig $config, DomainRef $domain): EppCodeResult
    {
        $code = $this->details($config, $domain, ['OrderDetails'])['domsecret'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new RegistrarException('ResellerClub returned no transfer code for this domain.');
        }

        return new EppCodeResult($code);
    }

    public function findSubmission(RegistrarConfig $config, DomainRef $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        if ($command === 'renew') {
            $order = $this->existingOrder($config, $domain);

            if ($order === null) {
                return new SubmissionLookup(ReconcileStatus::Unknown, message: 'The domain is not in the ResellerClub account.');
            }

            $expiry = self::epoch($order['orders.endtime'] ?? null);
            // Dates, not instants: ResellerClub's epoch has a time of day, ours is a date. Executed only when it moved on.
            $renewed = $expiry !== null && $domain->expiryDate !== null && $expiry->toDateString() > $domain->expiryDate->addDay()->utc()->toDateString();

            return new SubmissionLookup($renewed ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $renewed ? $expiry : null, ['order_id' => (int) $order['orders.orderid']]);
        }

        // Ours: the order whose id the host holds, or one of our customer created since the submission began.
        $order = $this->existingOrder($config, $domain, since: $submittedAt, bySearch: true);

        if ($order === null && ! isset($domain->remote['order_id']) && $this->existingOrder($config, $domain, bySearch: true) !== null) {
            return new SubmissionLookup(ReconcileStatus::Conflict, message: 'The name is held in the ResellerClub account by an order that was not placed by this submission; link it by hand.');
        }

        if ($order !== null) {
            $remote = ['order_id' => (int) $order['orders.orderid']];

            return $order['entity.currentstatus'] === 'Active'
                ? new SubmissionLookup(ReconcileStatus::Executed, self::epoch($order['orders.endtime'] ?? null), $remote)
                : new SubmissionLookup(ReconcileStatus::InProgress, remote: $remote);
        }

        $status = $this->checkAvailability($config, $domain->sld, [$domain->tld])[0]->status ?? 'unknown';

        return match ($status) {
            'available' => new SubmissionLookup(ReconcileStatus::NotExecuted),
            'registered' => $command === 'transfer'
                ? new SubmissionLookup(ReconcileStatus::NotExecuted, message: 'No transfer order for this name is in the ResellerClub account.')
                : new SubmissionLookup(ReconcileStatus::Conflict, message: 'The name is registered, but not in the ResellerClub account.'),
            default => new SubmissionLookup(ReconcileStatus::Unknown, message: 'The registry did not answer the availability check.'),
        };
    }

    public function testConnection(RegistrarConfig $config): ConnectionCheck
    {
        try {
            $this->client->get($config, 'domains/available.json', ['domain-name' => ['connection-check'], 'tlds' => ['com']], check: true);
        } catch (RegistrarException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        return new ConnectionCheck(true, $config->testMode ? 'Connected to the ResellerClub demo account.' : 'Connected to the ResellerClub account.');
    }

    /**
     * The newest order for the domain's name that is tied to this domain, or null. Tied means: the order id the host
     * recorded from our own register or transfer answer or from findSubmission. Only with $bySearch (register, transfer
     * and findSubmission, which settle a submission) and no recorded id, an order of the client's own ResellerClub
     * customer, created at or after $since when given; that is never enough to read, sync or change an order. The search is made with the customer-id filter (and creation-date-start), and every row is
     * checked again, so a registrar that ignores a filter cannot widen it. Orders that no longer hold the name count
     * only with $includeGone. Pages are read until the count ResellerClub reports is covered, at most SEARCH_PAGES.
     *
     * @return array<string, string>|null
     */
    private function existingOrder(RegistrarConfig $config, DomainRef $domain, bool $includeGone = false, ?CarbonImmutable $since = null, bool $bySearch = false): ?array
    {
        $held = $domain->remote['order_id'] ?? null;
        $customer = $held === null && $bySearch ? $this->contacts->knownCustomerId($config, $domain) : null;

        if ($held === null && $customer === null) {
            return null;
        }

        $params = ['no-of-records' => self::SEARCH_ROWS, 'domain-name' => $domain->name, 'order-by' => 'orderid desc'];

        if ($customer !== null) {
            $params['customer-id'] = $customer;
        }

        if ($held === null && $since !== null) {
            $params['creation-date-start'] = $since->subSeconds(self::CLOCK_SLACK)->getTimestamp();
        }

        $found = null;

        for ($page = 1; $page <= self::SEARCH_PAGES; $page++) {
            $answer = (array) $this->client->get($config, 'domains/search.json', [...$params, 'page-no' => $page]);
            $rows = 0;

            foreach ($answer as $key => $row) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    continue;
                }

                $rows++;
                $row = array_map('strval', array_filter((array) $row, 'is_scalar'));

                if (strtolower($row['entity.description'] ?? '') === $domain->name
                    && ($includeGone || ! in_array($row['entity.currentstatus'] ?? '', self::GONE, true))
                    && ($held !== null ? (string) ($row['orders.orderid'] ?? '') === (string) $held : ($row['entity.customerid'] ?? null) === $customer)
                    && ($held !== null || $since === null || self::createdBy($row, $since))
                    && isset($row['orders.orderid'])
                    && ($found === null || (int) $row['orders.orderid'] > (int) $found['orders.orderid'])) {
                    $found = $row;
                }
            }

            if ($rows === 0 || $page * self::SEARCH_ROWS >= (int) ($answer['recsindb'] ?? 0)) {
                break;
            }
        }

        return $found;
    }

    /**
     * Whether a search row was created by the submission that began at $since: within the register budget of it, with
     * clock slack on both sides. A later order of the same name (a panel order, another install on the customer) is
     * outside the window and is never adopted by a lingering claim.
     *
     * @param  array<string, string>  $row
     */
    private static function createdBy(array $row, CarbonImmutable $since): bool
    {
        $created = (int) ($row['orders.creationdt'] ?? 0);

        return $created >= $since->getTimestamp() - self::CLOCK_SLACK
            && $created <= $since->getTimestamp() + self::REGISTER_BUDGET + self::CLOCK_SLACK;
    }

    /**
     * An existing order of the name may be adopted by register or transfer only when the host already holds its id
     * (existingOrder() found it that way); otherwise it is a conflict for staff and nothing is adopted or sent.
     *
     * @param  array<string, string>  $order  a search row
     * @return array<string, string>
     *
     * @throws RegistrarException
     */
    private function ours(array $order, DomainRef $domain): array
    {
        if (isset($domain->remote['order_id']) && (string) $domain->remote['order_id'] === $order['orders.orderid']) {
            return $order;
        }

        throw new RegistrarException($domain->name.' is held in the ResellerClub account by another order; link it by hand. Nothing was sent.');
    }

    /**
     * Hand a transfer code to an order that waits for it. ResellerClub refuses a second code for an order that already
     * has one; that is an earlier attempt having worked, not a failure.
     *
     * @return string|null a message when the code was already sent
     *
     * @throws RegistrarException
     */
    private function submitCode(RegistrarConfig $config, string $orderId, #[\SensitiveParameter] string $code): ?string
    {
        try {
            $this->client->post($config, 'domains/transfer/submit-auth-code.json', ['order-id' => $orderId, 'auth-code' => $code]);
        } catch (RegistrarTimeout $timeout) {
            throw $timeout;
        } catch (RegistrarException $refused) {
            if ($refused->retryable) {
                throw $refused;
            }

            // Tell the cases apart by what ResellerClub says: a wrong code is the client's to fix; an order that no
            // longer waits for a code already has one. Anything else is reported as it is.
            if (preg_match('/not valid|invalid|incorrect|wrong|does not match/i', $refused->getMessage()) === 1) {
                throw new RegistrarException('ResellerClub says the transfer code is not valid. Ask the current registrar for the code and enter it again.', previous: $refused);
            }

            if (preg_match('/already|no transfer waiting|not waiting/i', $refused->getMessage()) === 1) {
                return 'The transfer code was already sent to ResellerClub; the order is waiting for the current registrar. If it stays that way, check the order in the ResellerClub panel.';
            }

            throw $refused;
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function details(RegistrarConfig $config, DomainRef $domain, array $options): array
    {
        $this->requireLinkedOrder($domain);
        $details = (array) $this->client->get($config, 'domains/details-by-name.json', ['domain-name' => $domain->name, 'options' => $options]);

        if (! isset($details['orderid'])) {
            throw new RegistrarException('ResellerClub returned no order for '.$domain->name.'.', retryable: true);
        }

        if ((string) $details['orderid'] !== (string) $domain->remote['order_id']) {
            throw new RegistrarException('The ResellerClub order for '.$domain->name.' is not the one recorded for this domain; nothing was read or changed.');
        }

        return $details;
    }

    /**
     * The only proof an order is this domain's is the id recorded from our own register or transfer answer (or from
     * findSubmission inside our submission window). Reads, syncs, EPP codes and changes need it; an order found by
     * searching for the name is never linked here.
     *
     * @throws RegistrarException
     */
    private function requireLinkedOrder(DomainRef $domain): void
    {
        if (isset($domain->remote['order_id']) && $domain->remote['order_id'] !== '') {
            return;
        }

        throw new RegistrarException($domain->status === 'pending_registration'
            ? 'Registration not confirmed at ResellerClub: no order is linked to '.$domain->name.'. Check the order in the ResellerClub panel; nothing was changed.'
            : 'No ResellerClub order is linked to '.$domain->name.'; nothing was read or changed. Link the order by hand.');
    }

    private function orderId(RegistrarConfig $config, DomainRef $domain): string
    {
        $id = $domain->remote['order_id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : (string) $this->details($config, $domain, ['OrderDetails'])['orderid'];
    }

    /**
     * The customer, contacts and fixed options a register or transfer order sends.
     *
     * @return array<string, string>
     */
    private function orderParams(RegistrarConfig $config, RegistrationRequest $request): array
    {
        $customer = $this->contacts->customerId($config, $request->domain, $request->contacts);

        return [
            'domain-name' => $request->domain->name,
            'customer-id' => $customer,
            ...$this->contacts->contactIds($config, $request->domain, $request->contacts, $customer),
            'invoice-option' => 'NoInvoice',
            'auto-renew' => 'false',
            ...($request->idProtection ? ['purchase-privacy' => 'true', 'protect-privacy' => 'true'] : []),
        ];
    }

    /**
     * Completed when ResellerClub says Success, pending when it says PendingExecution (sync settles it). Any other
     * actionstatus is not a confirmation: the order is read again, and counts as completed only when it is Active (and,
     * for a renewal, its expiry moved past $previousEnd). A completed change that moves the expiry is read back for the
     * registry's new date; a failed read-back leaves the date to the host.
     */
    private function actionResult(RegistrarConfig $config, DomainRef $domain, mixed $answer, bool $readBack = true, ?string $previousEnd = null): RegistrarResult
    {
        $answer = (array) $answer;
        $recorded = self::orderRemote($answer);

        // The order id in this very answer is the proof the read-back needs; without one nothing is read.
        if (! isset($domain->remote['order_id']) && $recorded !== []) {
            $domain = new DomainRef(...[...get_object_vars($domain), 'remote' => [...$domain->remote, ...$recorded]]);
        }

        $readBack = $readBack && isset($domain->remote['order_id']);
        $said = $answer['actionstatus'] ?? null;
        $status = $said === 'Success' ? 'completed' : 'pending';
        $expiry = null;

        if ($readBack && ($status === 'completed' || $said !== 'PendingExecution')) {
            try {
                $details = $this->details($config, $domain, ['OrderDetails']);
                $expiry = self::epoch($details['endtime'] ?? null);

                if ($said !== 'Success') {
                    $moved = $previousEnd === null || (int) ($details['endtime'] ?? 0) > (int) $previousEnd;
                    $status = ($details['currentstatus'] ?? null) === 'Active' && $moved ? 'completed' : 'pending';
                }
            } catch (RegistrarException) {
                $expiry = null;
            }
        }

        $message = (string) ($answer['actionstatusdesc'] ?? '');

        return new RegistrarResult($status, $status === 'completed' ? $expiry : null, $message === '' ? null : $message, remote: self::orderRemote($answer));
    }

    /**
     * @param  array<string, string>  $order  a search row
     */
    private function resultFor(array $order, string $message): RegistrarResult
    {
        $active = $order['entity.currentstatus'] === 'Active';

        return new RegistrarResult($active ? 'completed' : 'pending', $active ? self::epoch($order['orders.endtime'] ?? null) : null, $message, remote: ['order_id' => (int) $order['orders.orderid']]);
    }

    /**
     * @return 'completed'|'pending'
     */
    private static function status(mixed $answer): string
    {
        return (((array) $answer)['actionstatus'] ?? null) === 'Success' ? 'completed' : 'pending';
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @return array<string, int>
     */
    private static function orderRemote(array $answer): array
    {
        return is_numeric($answer['entityid'] ?? null) ? ['order_id' => (int) $answer['entityid']] : [];
    }

    private static function epoch(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) && (int) $value > 0 ? CarbonImmutable::createFromTimestamp((int) $value) : null;
    }

    /**
     * `.np` names are registered by the client at the registry (registrars.md §9); the host refuses them first, and the
     * driver refuses them again so no call can reach ResellerClub.
     */
    private static function refuseSelfRegistered(DomainRef $domain): void
    {
        if ($domain->tld === '.np' || str_ends_with($domain->tld, '.np')) {
            throw new RegistrarException('.np domains are registered by the client at the registry, not through ResellerClub.');
        }
    }
}
