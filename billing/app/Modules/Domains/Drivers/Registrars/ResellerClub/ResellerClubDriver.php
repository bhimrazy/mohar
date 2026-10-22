<?php

namespace App\Modules\Domains\Drivers\Registrars\ResellerClub;

use App\Contracts\Domains\Registrar\ChecksAvailability;
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
use Carbon\CarbonImmutable;

/**
 * ResellerClub (LogicBoxes) registrar driver (registrars.md §3), over its HTTP API through ResellerClubClient.
 *
 * Orders are placed with `invoice-option=NoInvoice` (the host bills the client; the price comes off the reseller
 * balance) and `auto-renew=false` (the host renews). The order id is kept as `remote.order_id`.
 *
 * Never twice: before a register or transfer is sent, the account is searched for an order of that name. An order
 * found there (an earlier attempt whose answer was lost) is reported instead of sending another; a renewal names the
 * current expiry (`exp-date`), which ResellerClub refuses once the renewal ran. After an unknown outcome the host
 * settles it through findSubmission(), which reads the same order search.
 */
final class ResellerClubDriver implements ChecksAvailability, ManagesContacts, ManagesIdProtection, ManagesLock, ManagesNameservers, ProvidesEppCode, ReconcilesSubmissions, RegistrarDriver, RestoresDomains, SyncsDomains, TestsRegistrarConnection, TransfersDomains
{
    public const KEY = 'resellerclub';

    /** Order states that no longer hold the name. */
    private const GONE = ['Deleted', 'Archived'];

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
            new SettingField('reseller_id', 'Reseller id', required: true, help: 'Shown under Manage Profile in the ResellerClub panel.'),
            new SettingField('api_key', 'API key', FieldType::Secret, help: 'Settings → API in the ResellerClub panel. Regenerating it stops the old key at once.'),
            new SettingField('demo_reseller_id', 'Demo reseller id', help: 'Used only in test mode, with the demo account. The demo account is wiped every day.'),
            new SettingField('demo_api_key', 'Demo API key', FieldType::Secret, help: 'Used only in test mode. Live credentials are never sent to the demo host.'),
            new SettingField('customer_mode', 'Customers at ResellerClub', FieldType::Select, options: ['default_customer' => 'One default customer for every domain', 'per_client' => 'One customer per client'], default: 'default_customer'),
            new SettingField('default_customer_id', 'Default customer id', help: 'The ResellerClub customer that holds every domain when one default customer is chosen.'),
            new SettingField('http_proxy', 'Outbound proxy', FieldType::Secret, help: 'Optional, for example http://user:pass@proxy.example:3128, when the API allow-list needs a fixed IP address this server lacks.'),
        ];
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

        if (($earlier = $this->existingOrder($config, $request->domain)) !== null) {
            return $this->resultFor($config, $request->domain, $earlier, 'Already in the ResellerClub account; not sent again.');
        }

        $answer = $this->client->post($config, 'domains/register.json', [
            ...$this->orderParams($config, $request),
            'years' => $request->years,
            'ns' => $request->nameservers,
        ]);

        return $this->actionResult($config, $request->domain, $answer);
    }

    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        self::refuseSelfRegistered($request->domain);
        $code = (string) $request->eppCode;

        if (($earlier = $this->existingOrder($config, $request->domain)) !== null) {
            if ($earlier['entity.currentstatus'] === 'InActive' && $code !== '') {
                // A transfer that waits for its code: hand the code to that order instead of ordering again.
                $this->client->post($config, 'domains/transfer/submit-auth-code.json', ['order-id' => $earlier['orders.orderid'], 'auth-code' => $code]);
            }

            return new RegistrarResult('pending', message: 'The transfer is already in the ResellerClub account; not sent again.', remote: ['order_id' => (int) $earlier['orders.orderid']]);
        }

        $answer = (array) $this->client->post($config, 'domains/transfer.json', [
            ...$this->orderParams($config, $request),
            ...($code === '' ? [] : ['auth-code' => $code]),
            ...($request->nameservers === [] ? [] : ['ns' => $request->nameservers]),
        ]);

        return new RegistrarResult('pending', message: (string) ($answer['actionstatusdesc'] ?? 'Transfer requested at ResellerClub.'), remote: self::orderRemote($answer));
    }

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
    {
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
        $details = $this->details($config, $domain, ['OrderDetails']);
        $answer = $this->client->post($config, 'domains/renew.json', [
            'order-id' => $details['orderid'], 'years' => $years, 'exp-date' => (string) $details['endtime'],
            'invoice-option' => 'NoInvoice', 'auto-renew' => 'false',
        ]);

        return $this->actionResult($config, $domain, $answer);
    }

    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        $answer = $this->client->post($config, 'domains/restore.json', ['order-id' => $this->orderId($config, $domain), 'invoice-option' => 'NoInvoice']);

        return $this->actionResult($config, $domain, $answer);
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
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
            $elsewhere = ($this->checkAvailability($config, $domain->sld, [$domain->tld])[0] ?? null)?->status === 'registered';

            return new SyncResult(false, cancelled: ! $elsewhere, transferredAway: $elsewhere);
        }

        return new SyncResult($info->status === 'active', cancelled: $info->status === 'cancelled', expiryDate: $info->expiryDate, remote: $info->remote);
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
        $order = $this->existingOrder($config, $domain);

        if ($command === 'renew') {
            if ($order === null) {
                return new SubmissionLookup(ReconcileStatus::Unknown, message: 'The domain is not in the ResellerClub account.');
            }

            $expiry = self::epoch($order['orders.endtime'] ?? null);
            $renewed = $expiry !== null && $domain->expiryDate !== null && $expiry->gt($domain->expiryDate);

            return new SubmissionLookup($renewed ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $renewed ? $expiry : null, ['order_id' => (int) $order['orders.orderid']]);
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
     * The newest order for the domain's name in the account, from the order search, or null. Orders that no longer
     * hold the name count only with $includeGone.
     *
     * @return array<string, string>|null
     */
    private function existingOrder(RegistrarConfig $config, DomainRef $domain, bool $includeGone = false): ?array
    {
        $answer = (array) $this->client->get($config, 'domains/search.json', ['no-of-records' => 10, 'page-no' => 1, 'domain-name' => $domain->name, 'order-by' => 'orderid desc']);
        $found = null;

        foreach ($answer as $key => $row) {
            if (is_int($key) || ctype_digit((string) $key)) {
                $row = (array) $row;

                if (strtolower((string) ($row['entity.description'] ?? '')) === $domain->name
                    && ($includeGone || ! in_array($row['entity.currentstatus'] ?? '', self::GONE, true))
                    && ($found === null || (int) $row['orders.orderid'] > (int) $found['orders.orderid'])) {
                    $found = array_map('strval', array_filter($row, 'is_scalar'));
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function details(RegistrarConfig $config, DomainRef $domain, array $options): array
    {
        $details = (array) $this->client->get($config, 'domains/details-by-name.json', ['domain-name' => $domain->name, 'options' => $options]);

        if (! isset($details['orderid'])) {
            throw new RegistrarException('ResellerClub returned no order for '.$domain->name.'.', retryable: true);
        }

        return $details;
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
     * Completed when ResellerClub says Success, else pending (sync settles it). A completed change that moves the
     * expiry is read back for the registry's new date; a failed read-back leaves the date to the host.
     */
    private function actionResult(RegistrarConfig $config, DomainRef $domain, mixed $answer, bool $readBack = true): RegistrarResult
    {
        $status = self::status($answer);
        $expiry = null;

        if ($readBack && $status === 'completed') {
            try {
                $expiry = self::epoch($this->details($config, $domain, ['OrderDetails'])['endtime'] ?? null);
            } catch (RegistrarException) {
                $expiry = null;
            }
        }

        return new RegistrarResult($status, $expiry, (string) (((array) $answer)['actionstatusdesc'] ?? '') ?: null, remote: self::orderRemote((array) $answer));
    }

    /**
     * @param  array<string, string>  $order  a search row
     */
    private function resultFor(RegistrarConfig $config, DomainRef $domain, array $order, string $message): RegistrarResult
    {
        $active = $order['entity.currentstatus'] === 'Active';

        return new RegistrarResult($active ? 'completed' : 'pending', $active ? self::epoch($order['orders.endtime'] ?? null) : null, $message, remote: ['order_id' => (int) $order['orders.orderid']]);
    }

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
