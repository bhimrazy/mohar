<?php

namespace Mohar\Simulators\Providers\Namecheap;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * One Namecheap reseller account and the registry behind it, as the XML API shows it: domains that are registered,
 * renewed, locked, privacy-protected and moved, and transfers that wait on the losing registrar.
 *
 * Every answer is HTTP 200 with an `<ApiResponse Status="OK|ERROR">` root, errors included. An error carries a number
 * and a message and changes nothing. The numbers the registrar specification names are used as they are; the others,
 * and all messages, are this simulator's own and marked "assumed" in the README. Dates are `MM/DD/YYYY`, amounts are
 * USD decimals (kept as cents in the state), booleans are the strings `true` and `false`.
 *
 * Name rules a scenario can rely on: an SLD starting `premium-` is a premium name; one starting `slow-` registers
 * asynchronously (`NonRealTimeDomain`, not in the account until `settle_order`); names in the `foreign` namespace are
 * held at another registrar.
 */
final class NamecheapApi
{
    public const NAMESPACE_URI = 'http://api.namecheap.com/xml.response';

    /** Global errors (authentication and the allow-list). */
    public const E_MISSING_API_USER = 1010101;

    public const E_MISSING_API_KEY = 1010102;

    public const E_MISSING_USER_NAME = 1010103;

    public const E_MISSING_COMMAND = 1010104;

    public const E_MISSING_CLIENT_IP = 1010105;

    public const E_BAD_KEY = 1011102;

    public const E_BAD_USER_NAME = 1011147;

    public const E_IP_NOT_ALLOWED = 1011150;

    public const E_UNKNOWN_COMMAND = 1010900;

    /** Command errors. */
    public const E_MISSING_PARAMETER = 2010302;

    public const E_INVALID_PARAMETER = 2011170;

    public const E_TLD_NOT_SUPPORTED = 2030280;

    public const E_NOT_OURS = 2016166;

    public const E_NOT_FOUND = 2019166;

    public const E_EXPIRED = 2020166;

    public const E_NOT_EXPIRED = 2020167;

    public const E_NOT_AVAILABLE = 3019166;

    public const E_FUNDS = 2033409;

    /** Extensions the account sells: price in US cents, API transfer, registrar lock, extended attributes (name => pattern). */
    public const TLDS = [
        'com' => ['price' => 1_098, 'transfer' => true, 'lock' => true],
        'net' => ['price' => 1_298, 'transfer' => true, 'lock' => true],
        'org' => ['price' => 1_198, 'transfer' => true, 'lock' => true],
        'info' => ['price' => 1_498, 'transfer' => true, 'lock' => true],
        'xyz' => ['price' => 299, 'transfer' => false, 'lock' => true],
        'in' => ['price' => 899, 'transfer' => true, 'lock' => true],
        'us' => ['price' => 899, 'transfer' => true, 'lock' => true, 'attributes' => ['RegistrantNexus' => '/^C(11|12|21|31|32)$/', 'RegistrantPurpose' => '/^P[1-5]$/']],
        'ca' => ['price' => 1_199, 'transfer' => true, 'lock' => false, 'attributes' => ['CIRALegalType' => '/^[A-Z]{2,3}$/', 'CIRAWhoisDisplay' => '/^(Full|Private)$/', 'CIRALanguage' => '/^(EN|FR)$/']],
        'eu' => ['price' => 899, 'transfer' => false, 'lock' => false, 'attributes' => ['EUAgreeWhoisPolicy' => '/^YES$/', 'EUAgreeDeletePolicy' => '/^YES$/']],
        'co.uk' => ['price' => 899, 'transfer' => false, 'lock' => false],
        'uk' => ['price' => 999, 'transfer' => false, 'lock' => false],
    ];

    public const PREMIUM_PRICE = 12_000;

    /** What reactivating an expired name costs on top of the renewal, in cents. */
    public const REACTIVATE_FEE = 8_000;

    public const ROLES = ['Registrant', 'Tech', 'Admin', 'AuxBilling'];

    private const CONTACT_FIELDS = ['FirstName', 'LastName', 'Address1', 'City', 'StateProvince', 'PostalCode', 'Country', 'Phone', 'EmailAddress'];

    private const OPTIONAL_CONTACT_FIELDS = ['OrganizationName', 'JobTitle', 'Address2', 'Fax', 'PhoneExt'];

    /** Transfer statuses and their text; ids not listed read "Status <id>". The wording is ours. */
    public const TRANSFER_STATUSES = [
        -22 => 'Waiting for the authorization code',
        -5 => 'Waiting for the current registrar to confirm',
        -2 => 'Waiting for the transfer to be approved',
        -1 => 'Waiting for the current registrar to release the name',
        2 => 'Cancelled: the transfer was rejected',
        4 => 'Cancelled: the transfer was not completed in time',
        5 => 'Completed',
        8 => 'Cancelled: the current registrar did not release the name',
    ];

    public function __construct(private readonly Scenario $scenario) {}

    // ----------------------------------------------------------------- domains.check

    public function check(Request $request): Response
    {
        $input = $request->input();
        $list = array_values(array_filter(array_map(trim(...), explode(',', (string) ($input['DomainList'] ?? '')))));

        if ($list === []) {
            return self::missing($input['Command'], 'DomainList');
        }

        if (count($list) > 50) {
            return self::error($input['Command'], self::E_INVALID_PARAMETER, 'DomainList takes at most 50 names.');
        }

        $down = (array) $this->scenario->state()->get('config', 'registry_down', []);
        $rows = '';

        foreach ($list as $given) {
            $name = strtolower($given);
            [, $tld] = self::split($name);
            $attributes = ['Domain' => $given, 'Available' => 'false', 'ErrorNo' => '0', 'Description' => '', 'IsPremiumName' => 'false',
                'PremiumRegistrationPrice' => '0.0000', 'PremiumRenewalPrice' => '0.0000', 'PremiumRestorePrice' => '0.0000', 'PremiumTransferPrice' => '0.0000', 'IcannFee' => '0.0000', 'EapFee' => '0.0000'];

            if ($tld === null) {
                $attributes = [...$attributes, 'ErrorNo' => '2030280', 'Description' => 'The extension of this name is not offered by this registrar.'];
            } elseif (in_array($tld, $down, true)) {
                $attributes = [...$attributes, 'ErrorNo' => '3031510', 'Description' => 'The registry for this extension did not answer.'];
            } elseif (! $this->taken($name)) {
                $attributes['Available'] = 'true';

                if (self::premium($name)) {
                    $price = self::dollars(self::PREMIUM_PRICE);
                    $attributes = [...$attributes, 'IsPremiumName' => 'true', 'PremiumRegistrationPrice' => $price, 'PremiumRenewalPrice' => $price, 'PremiumRestorePrice' => $price, 'PremiumTransferPrice' => $price];
                }
            }

            $rows .= '<DomainCheckResult'.self::attributes($attributes).' />';
        }

        return self::ok($input['Command'], $rows);
    }

    // ----------------------------------------------------------------- domains.create

    public function create(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        foreach (['DomainName', 'Years'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return self::missing($command, $required);
            }
        }

        $name = strtolower((string) $input['DomainName']);
        [, $tld] = self::split($name);
        $years = (int) $input['Years'];

        if ($invalid = self::invalidName($command, $name, $input)) {
            return $invalid;
        }

        if ($tld === null) {
            return self::error($command, self::E_TLD_NOT_SUPPORTED, 'The extension of '.$name.' is not offered by this registrar.');
        }

        if ($years < 1 || $years > 10) {
            return self::error($command, self::E_INVALID_PARAMETER, 'Years must be 1 to 10.');
        }

        if (($contacts = self::contacts($command, $input)) instanceof Response) {
            return $contacts;
        }

        foreach (self::TLDS[$tld]['attributes'] ?? [] as $attribute => $pattern) {
            if (preg_match($pattern, (string) ($input[$attribute] ?? '')) !== 1) {
                return self::error($command, self::E_INVALID_PARAMETER, 'The .'.$tld.' registry needs the extra detail '.$attribute.' for this order'.(isset($input[$attribute]) ? ': "'.$input[$attribute].'" is not accepted.' : '.'));
            }
        }

        $nameservers = array_values(array_filter(array_map(trim(...), explode(',', (string) ($input['Nameservers'] ?? '')))));

        if ($nameservers !== [] && ($bad = self::invalidNameservers($command, $nameservers))) {
            return $bad;
        }

        $premium = self::premium($name);

        if ($premium && (strtolower((string) ($input['IsPremiumDomain'] ?? '')) !== 'true' || (int) round(((float) ($input['PremiumPrice'] ?? 0)) * 100) !== self::PREMIUM_PRICE)) {
            return self::error($command, self::E_INVALID_PARAMETER, $name.' is a premium name; send IsPremiumDomain=True and PremiumPrice with its price to accept it.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $name, $tld, $years, $contacts, $nameservers, $input, $premium): Response {
            if ($this->taken($name)) {
                return self::error($command, self::E_NOT_AVAILABLE, 'Domain not available.');
            }

            $price = ($premium ? self::PREMIUM_PRICE : self::TLDS[$tld]['price']) * $years;

            if ($funds = $this->charge($command, $price)) {
                return $funds;
            }

            $async = str_starts_with($name, 'slow-');
            $privacy = strtolower((string) ($input['AddFreeWhoisguard'] ?? '')) === 'yes' && strtolower((string) ($input['WGEnabled'] ?? '')) === 'yes';
            $domain = [
                'name' => $name, 'tld' => $tld, 'id' => (string) (8_100_000 + $this->scenario->state()->increment('ids', 'domain')), 'order' => $this->nextId('order', 5_100_000),
                'created' => time(), 'expires' => $async ? null : self::years(time(), $years), 'years' => $years, 'locked' => self::TLDS[$tld]['lock'], 'pending' => $async,
                'nameservers' => $nameservers, 'contacts' => $contacts, 'privacy' => ['id' => (string) (7_100_000 + $this->scenario->state()->increment('ids', 'privacy')), 'enabled' => $privacy, 'email' => $privacy ? 'privacy-'.$name.'@whoisguard.example' : '', 'forward' => ''],
                'attributes' => array_intersect_key($input, self::TLDS[$tld]['attributes'] ?? []), 'charged' => $price, 'lock_until' => null,
            ];
            $this->scenario->state()->put('domains', $name, $domain);

            return self::ok($command, '<DomainCreateResult'.self::attributes([
                'Domain' => $name, 'Registered' => $async ? 'false' : 'true', 'ChargedAmount' => self::dollars($price), 'DomainID' => $domain['id'], 'OrderID' => $domain['order'],
                'TransactionID' => $this->nextId('transaction', 3_100_000), 'WhoisguardEnable' => $privacy ? 'true' : 'false', 'NonRealTimeDomain' => $async ? 'true' : 'false',
            ]).' />');
        });
    }

    // ----------------------------------------------------------------- transfers

    public function transferCreate(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        foreach (['DomainName', 'Years'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return self::missing($command, $required);
            }
        }

        $name = strtolower((string) $input['DomainName']);
        [, $tld] = self::split($name);

        if ($invalid = self::invalidName($command, $name, $input)) {
            return $invalid;
        }

        if ($tld === null || ! self::TLDS[$tld]['transfer']) {
            return self::error($command, self::E_TLD_NOT_SUPPORTED, 'The extension of '.$name.' cannot be transferred through the API.');
        }

        $code = (string) ($input['EPPCode'] ?? '');

        if (str_starts_with($code, 'base64:')) {
            $decoded = base64_decode(substr($code, 7), true);
            $code = $decoded === false ? '' : $decoded;
        }

        if ($code === '') {
            return self::missing($command, 'EPPCode');
        }

        if ((int) $input['Years'] !== 1) {
            return self::error($command, self::E_INVALID_PARAMETER, 'A transfer includes one year.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $name, $tld, $code): Response {
            $foreign = $this->foreign($name);

            if ($foreign === null || $this->scenario->state()->get('domains', $name) !== null) {
                return self::error($command, self::E_NOT_AVAILABLE, $name.' cannot be transferred: it is not registered at another registrar.');
            }

            foreach ($this->scenario->state()->all('transfers') as $transfer) {
                if ($transfer['domain'] === $name && $transfer['status_id'] === -1) {
                    return self::error($command, self::E_NOT_AVAILABLE, 'A transfer of '.$name.' is already in progress.');
                }
            }

            if (! hash_equals((string) $foreign['epp'], $code)) {
                return self::error($command, self::E_INVALID_PARAMETER, 'The authorization code for '.$name.' is not valid.');
            }

            $price = self::TLDS[$tld]['price'];

            if ($funds = $this->charge($command, $price)) {
                return $funds;
            }

            $id = $this->nextId('transfer', 400_000);
            $this->scenario->state()->put('transfers', $id, ['id' => $id, 'domain' => $name, 'status_id' => -1, 'created' => time(), 'status_at' => time(), 'order' => $this->nextId('order', 5_100_000), 'charged' => $price]);

            return self::ok($command, '<DomainTransferCreateResult'.self::attributes([
                'Transfer' => 'true', 'TransferID' => $id, 'StatusID' => '-1', 'OrderID' => $this->scenario->state()->get('transfers', $id)['order'], 'TransactionID' => $this->nextId('transaction', 3_100_000),
                'ChargedAmount' => self::dollars($price), 'StatusCode' => '-1', 'DomainName' => $name, 'WhoisguardEnable' => 'false',
            ]).' />');
        });
    }

    public function transferGetStatus(Request $request): Response
    {
        $input = $request->input();
        $transfer = $this->scenario->state()->get('transfers', (string) ($input['TransferID'] ?? ''));

        if (! isset($input['TransferID'])) {
            return self::missing($input['Command'], 'TransferID');
        }

        return $transfer === null
            ? self::error($input['Command'], self::E_NOT_FOUND, 'No transfer in this account has the id '.$input['TransferID'].'.')
            : self::ok($input['Command'], '<DomainTransferGetStatusResult'.self::attributes(['TransferID' => $transfer['id'], 'Status' => self::transferText($transfer['status_id']), 'StatusID' => (string) $transfer['status_id']]).' />');
    }

    public function transferGetList(Request $request): Response
    {
        $input = $request->input();
        $term = strtolower((string) ($input['SearchTerm'] ?? ''));
        $rows = '';

        foreach ($this->scenario->state()->all('transfers') as $transfer) {
            if ($term === '' || str_contains($transfer['domain'], $term)) {
                $rows .= '<Transfer'.self::attributes([
                    'ID' => $transfer['id'], 'DomainName' => $transfer['domain'], 'User' => NamecheapSimulator::USER_NAME, 'TransferDate' => self::date($transfer['created']),
                    'OrderID' => $transfer['order'], 'StatusID' => (string) $transfer['status_id'], 'Status' => self::transferText($transfer['status_id']), 'StatusDate' => self::date($transfer['status_at']),
                ]).' />';
            }
        }

        return self::ok($input['Command'], '<TransferGetListResult>'.$rows.'</TransferGetListResult><Paging><TotalItems>'.substr_count($rows, '<Transfer ').'</TotalItems><CurrentPage>1</CurrentPage><PageSize>20</PageSize></Paging>');
    }

    // ----------------------------------------------------------------- renew, reactivate

    public function renew(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        foreach (['DomainName', 'Years'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return self::missing($command, $required);
            }
        }

        $years = (int) $input['Years'];

        if ($years < 1 || $years > 10) {
            return self::error($command, self::E_INVALID_PARAMETER, 'Years must be 1 to 10.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $years): Response {
            $domain = $this->owned($command, (string) $input['DomainName']);

            if ($domain instanceof Response) {
                return $domain;
            }

            if ($domain['expires'] < time()) {
                return self::error($command, self::E_EXPIRED, $domain['name'].' has expired; reactivate it instead of renewing.');
            }

            $price = (self::premium($domain['name']) ? self::PREMIUM_PRICE : self::TLDS[$domain['tld']]['price']) * $years;

            if ($funds = $this->charge($command, $price)) {
                return $funds;
            }

            $domain['expires'] = self::years($domain['expires'], $years);
            $this->scenario->state()->put('domains', $domain['name'], $domain);

            return self::ok($command, '<DomainRenewResult'.self::attributes([
                'DomainName' => $domain['name'], 'DomainID' => $domain['id'], 'Renew' => 'true', 'OrderID' => $this->nextId('order', 5_100_000),
                'TransactionID' => $this->nextId('transaction', 3_100_000), 'ChargedAmount' => self::dollars($price),
            ]).'><DomainDetails><ExpiredDate>'.self::date($domain['expires']).'</ExpiredDate><NumYears>'.$years.'</NumYears></DomainDetails></DomainRenewResult>');
        });
    }

    public function reactivate(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        $years = (int) ($input['YearsToAdd'] ?? 1);

        if ($years < 1 || $years > 10) {
            return self::error($command, self::E_INVALID_PARAMETER, 'YearsToAdd must be 1 to 10.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $years): Response {
            $domain = $this->owned($command, (string) $input['DomainName']);

            if ($domain instanceof Response) {
                return $domain;
            }

            if ($domain['expires'] >= time()) {
                return self::error($command, self::E_NOT_EXPIRED, $domain['name'].' has not expired.');
            }

            $price = self::TLDS[$domain['tld']]['price'] * $years + self::REACTIVATE_FEE;

            if ($funds = $this->charge($command, $price)) {
                return $funds;
            }

            $domain['expires'] = self::years(time(), $years);
            $this->scenario->state()->put('domains', $domain['name'], $domain);

            return self::ok($command, '<DomainReactivateResult'.self::attributes([
                'Domain' => $domain['name'], 'IsSuccess' => 'true', 'OrderID' => $this->nextId('order', 5_100_000), 'TransactionID' => $this->nextId('transaction', 3_100_000), 'ChargedAmount' => self::dollars($price),
            ]).' />');
        });
    }

    // ----------------------------------------------------------------- reads

    public function getInfo(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        $domain = $this->owned($command, (string) $input['DomainName']);

        if ($domain instanceof Response) {
            return $domain;
        }

        $expired = $domain['expires'] < time();
        $status = $expired ? 'Expired' : ($domain['locked'] ? 'Locked' : 'Ok');
        $custom = $domain['nameservers'] !== [];
        $nameservers = implode('', array_map(fn (string $ns): string => '<Nameserver>'.self::e($ns).'</Nameserver>', $custom ? $domain['nameservers'] : NamecheapSimulator::DEFAULT_NAMESERVERS));

        return self::ok($command, '<DomainGetInfoResult'.self::attributes(['Status' => $status, 'ID' => $domain['id'], 'DomainName' => $domain['name'], 'OwnerName' => NamecheapSimulator::USER_NAME, 'IsOwner' => 'true', 'IsPremium' => self::premium($domain['name']) ? 'true' : 'false']).'>'
            .'<DomainDetails><CreatedDate>'.self::date($domain['created']).'</CreatedDate><ExpiredDate>'.self::date($domain['expires']).'</ExpiredDate><NumYearsToExpire>'.max(0, (int) floor(($domain['expires'] - time()) / 31_536_000)).'</NumYearsToExpire></DomainDetails>'
            .'<LockDetails />'
            .'<Whoisguard'.self::attributes(['Enabled' => $domain['privacy']['enabled'] ? 'True' : 'False']).'><ID>'.$domain['privacy']['id'].'</ID><ExpiredDate>'.self::date($domain['expires']).'</ExpiredDate>'
            .($domain['privacy']['enabled'] ? '<EmailDetails'.self::attributes(['WhoisGuardEmail' => $domain['privacy']['email'], 'ForwardedTo' => $domain['privacy']['forward']]).' />' : '').'</Whoisguard>'
            .'<DnsDetails'.self::attributes(['ProviderType' => $custom ? 'CUSTOM' : 'FREE', 'IsUsingOurDNS' => $custom ? 'false' : 'true', 'HostCount' => '0', 'EmailType' => 'NONE', 'DynamicDNSStatus' => 'false', 'IsFailover' => 'false']).'>'.$nameservers.'</DnsDetails>'
            .'<Modificationrights All="true" /></DomainGetInfoResult>');
    }

    public function getList(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];
        $size = (int) ($input['PageSize'] ?? 20);
        $page = (int) ($input['Page'] ?? 1);
        $type = strtoupper((string) ($input['ListType'] ?? 'ALL'));
        $term = strtolower((string) ($input['SearchTerm'] ?? ''));

        if ($size < 10 || $size > 100 || $page < 1 || ! in_array($type, ['ALL', 'EXPIRING', 'EXPIRED'], true)) {
            return self::error($command, self::E_INVALID_PARAMETER, 'PageSize is 10 to 100, Page starts at 1, and ListType is ALL, EXPIRING or EXPIRED.');
        }

        $domains = array_values(array_filter($this->scenario->state()->all('domains'), fn (array $domain): bool => ! $domain['pending']
            && ($term === '' || str_contains($domain['name'], $term))
            && match ($type) {
                'EXPIRED' => $domain['expires'] < time(),
                'EXPIRING' => $domain['expires'] >= time() && $domain['expires'] < time() + 30 * 86_400,
                default => true,
            }));
        usort($domains, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $rows = '';

        foreach (array_slice($domains, ($page - 1) * $size, $size) as $domain) {
            $rows .= '<Domain'.self::attributes([
                'ID' => $domain['id'], 'Name' => $domain['name'], 'User' => NamecheapSimulator::USER_NAME, 'Created' => self::date($domain['created']), 'Expires' => self::date($domain['expires']),
                'IsExpired' => $domain['expires'] < time() ? 'true' : 'false', 'IsLocked' => $domain['locked'] ? 'true' : 'false', 'AutoRenew' => 'false',
                'WhoisGuard' => $domain['privacy']['enabled'] ? 'ENABLED' : 'NOTPRESENT', 'IsPremium' => self::premium($domain['name']) ? 'true' : 'false', 'IsOurDNS' => $domain['nameservers'] === [] ? 'true' : 'false',
            ]).' />';
        }

        return self::ok($command, '<DomainGetListResult>'.$rows.'</DomainGetListResult><Paging><TotalItems>'.count($domains).'</TotalItems><CurrentPage>'.$page.'</CurrentPage><PageSize>'.$size.'</PageSize></Paging>');
    }

    public function getTldList(Request $request): Response
    {
        $rows = '';
        $sequence = 0;

        foreach (self::TLDS as $tld => $details) {
            $rows .= '<Tld'.self::attributes([
                'Name' => $tld, 'NonRealTime' => 'false', 'MinRegisterYears' => '1', 'MaxRegisterYears' => '10', 'MinRenewYears' => '1', 'MaxRenewYears' => '10', 'MinTransferYears' => '1', 'MaxTransferYears' => '1',
                'IsApiRegisterable' => 'true', 'IsApiRenewable' => 'true', 'IsApiTransferable' => $details['transfer'] ? 'true' : 'false', 'IsEppRequired' => 'true',
                'SupportsRegistrarLock' => $details['lock'] ? 'true' : 'false', 'SequenceNumber' => (string) ++$sequence, 'Type' => 'GTLD',
            ]).'>'.self::e($tld).'</Tld>';
        }

        return self::ok($request->input()['Command'], '<Tlds>'.$rows.'</Tlds>');
    }

    // ----------------------------------------------------------------- nameservers, lock, privacy, contacts

    public function setCustom(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        foreach (['SLD', 'TLD', 'Nameservers'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return self::missing($command, $required);
            }
        }

        $nameservers = array_values(array_filter(array_map(trim(...), explode(',', (string) $input['Nameservers']))));

        if (count($nameservers) < 2 || count($nameservers) > 12 || ($bad = self::invalidNameservers($command, $nameservers))) {
            return $bad ?? self::error($command, self::E_INVALID_PARAMETER, 'Send 2 to 12 name servers.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $nameservers): Response {
            $domain = $this->ownedBySldTld($command, (string) $input['SLD'], (string) $input['TLD']);

            if ($domain instanceof Response) {
                return $domain;
            }

            $this->scenario->state()->put('domains', $domain['name'], [...$domain, 'nameservers' => array_map('strtolower', $nameservers)]);

            return self::ok($command, '<DomainDNSSetCustomResult'.self::attributes(['Domain' => $domain['name'], 'Update' => 'true']).' />');
        });
    }

    public function dnsGetList(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        foreach (['SLD', 'TLD'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return self::missing($command, $required);
            }
        }

        $domain = $this->ownedBySldTld($command, (string) $input['SLD'], (string) $input['TLD']);

        if ($domain instanceof Response) {
            return $domain;
        }

        $custom = $domain['nameservers'] !== [];

        return self::ok($command, '<DomainDNSGetListResult'.self::attributes(['Domain' => $domain['name'], 'IsUsingOurDNS' => $custom ? 'false' : 'true', 'IsPremiumDNS' => 'false', 'IsUsingFreeDNS' => $custom ? 'false' : 'true']).'>'
            .implode('', array_map(fn (string $ns): string => '<Nameserver>'.self::e($ns).'</Nameserver>', $custom ? $domain['nameservers'] : NamecheapSimulator::DEFAULT_NAMESERVERS)).'</DomainDNSGetListResult>');
    }

    public function getRegistrarLock(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        $domain = $this->owned($command, (string) $input['DomainName']);

        return $domain instanceof Response
            ? $domain
            : self::ok($command, '<DomainGetRegistrarLockResult'.self::attributes(['Domain' => $domain['name'], 'RegistrarLockStatus' => $domain['locked'] ? 'true' : 'false']).' />');
    }

    public function setRegistrarLock(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        $action = strtoupper((string) ($input['LockAction'] ?? 'LOCK'));

        if (! in_array($action, ['LOCK', 'UNLOCK'], true)) {
            return self::error($command, self::E_INVALID_PARAMETER, 'LockAction is LOCK or UNLOCK.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $action): Response {
            $domain = $this->owned($command, (string) $input['DomainName']);

            if ($domain instanceof Response) {
                return $domain;
            }

            if (! self::TLDS[$domain['tld']]['lock']) {
                return self::error($command, self::E_TLD_NOT_SUPPORTED, 'The .'.$domain['tld'].' registry has no registrar lock.');
            }

            $this->scenario->state()->put('domains', $domain['name'], [...$domain, 'locked' => $action === 'LOCK']);

            return self::ok($command, '<DomainSetRegistrarLockResult'.self::attributes(['Domain' => $domain['name'], 'IsSuccess' => 'true']).' />');
        });
    }

    public function privacy(Request $request, bool $enable): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['WhoisguardID'] ?? '')) === '') {
            return self::missing($command, 'WhoisguardID');
        }

        $email = (string) ($input['ForwardedToEmail'] ?? '');

        if ($enable && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $email === '' ? self::missing($command, 'ForwardedToEmail') : self::error($command, self::E_INVALID_PARAMETER, 'ForwardedToEmail is not a valid email address.');
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $enable, $email): Response {
            foreach ($this->scenario->state()->all('domains') as $domain) {
                if ($domain['privacy']['id'] === (string) $input['WhoisguardID'] && ! $domain['pending']) {
                    $this->scenario->state()->put('domains', $domain['name'], [...$domain, 'privacy' => [...$domain['privacy'], 'enabled' => $enable, 'forward' => $enable ? $email : '']]);

                    return self::ok($command, '<'.($enable ? 'WhoisguardEnableResult' : 'WhoisguardDisableResult').self::attributes(['IsSuccess' => 'true']).' />');
                }
            }

            return self::error($command, self::E_NOT_FOUND, 'No privacy service in this account has the id '.$input['WhoisguardID'].'.');
        });
    }

    public function getContacts(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        $domain = $this->owned($command, (string) $input['DomainName']);

        if ($domain instanceof Response) {
            return $domain;
        }

        $roles = '';

        foreach (self::ROLES as $role) {
            $roles .= '<'.$role.' ReadOnly="false">';

            foreach ([...self::OPTIONAL_CONTACT_FIELDS, ...self::CONTACT_FIELDS] as $field) {
                $roles .= '<'.$field.'>'.self::e((string) ($domain['contacts'][$role][$field] ?? '')).'</'.$field.'>';
            }

            $roles .= '</'.$role.'>';
        }

        return self::ok($command, '<DomainContactsResult'.self::attributes(['Domain' => $domain['name'], 'domainnameid' => $domain['id']]).'>'.$roles.'</DomainContactsResult>');
    }

    public function setContacts(Request $request): Response
    {
        $input = $request->input();
        $command = (string) $input['Command'];

        if (trim((string) ($input['DomainName'] ?? '')) === '') {
            return self::missing($command, 'DomainName');
        }

        if (($contacts = self::contacts($command, $input)) instanceof Response) {
            return $contacts;
        }

        return $this->scenario->state()->transaction(function () use ($command, $input, $contacts): Response {
            $domain = $this->owned($command, (string) $input['DomainName']);

            if ($domain instanceof Response) {
                return $domain;
            }

            // A change of registrant on a generic extension starts the 60-day transfer lock; there is no way to opt out.
            $changed = ($domain['contacts']['Registrant'] ?? []) !== $contacts['Registrant'];
            $this->scenario->state()->put('domains', $domain['name'], [...$domain, 'contacts' => $contacts,
                'lock_until' => $changed && ! str_contains($domain['tld'], 'uk') ? time() + 60 * 86_400 : $domain['lock_until']]);

            return self::ok($command, '<DomainSetContactResult'.self::attributes(['Domain' => $domain['name'], 'IsSuccess' => 'true']).' />');
        });
    }

    // ----------------------------------------------------------------- control actions

    /** A `slow-` registration is answered by the registry: it joins the account, or fails and is refunded. */
    public function settleOrder(string $name, string $outcome): string
    {
        return $this->scenario->state()->transaction(function () use ($name, $outcome): string {
            $domain = $this->scenario->state()->get('domains', $name);

            if (! is_array($domain) || ! $domain['pending']) {
                throw new \InvalidArgumentException($name.' has no registration waiting for the registry.');
            }

            if ($outcome === 'failed') {
                $this->scenario->state()->put('config', 'balance', (int) $this->scenario->state()->get('config', 'balance', 0) + $domain['charged']);
                $this->scenario->state()->delete('domains', $name);

                return 'failed';
            }

            $this->scenario->state()->put('domains', $name, [...$domain, 'pending' => false, 'created' => time(), 'expires' => self::years(time(), $domain['years'])]);

            return 'active';
        });
    }

    public function settleTransfer(string $name, string $outcome): string
    {
        return $this->scenario->state()->transaction(function () use ($name, $outcome): string {
            foreach ($this->scenario->state()->all('transfers') as $transfer) {
                if ($transfer['domain'] !== $name || $transfer['status_id'] === 5) {
                    continue;
                }

                if (! in_array($outcome, ['complete', 'cancel'], true)) {
                    throw new \InvalidArgumentException('The outcome is complete or cancel.');
                }

                if ($outcome === 'cancel') {
                    $this->scenario->state()->put('config', 'balance', (int) $this->scenario->state()->get('config', 'balance', 0) + $transfer['charged']);
                    $this->scenario->state()->put('transfers', $transfer['id'], [...$transfer, 'status_id' => 8, 'status_at' => time()]);

                    return 'cancelled';
                }

                $foreign = $this->foreign($name);
                [, $tld] = self::split($name);
                $tld ?? throw new \InvalidArgumentException($name.' has an extension this account does not sell.');
                $this->scenario->state()->put('transfers', $transfer['id'], [...$transfer, 'status_id' => 5, 'status_at' => time()]);
                $this->scenario->state()->delete('foreign', $name);
                $this->scenario->state()->put('domains', $name, [
                    'name' => $name, 'tld' => $tld, 'id' => (string) (8_100_000 + $this->scenario->state()->increment('ids', 'domain')), 'order' => $transfer['order'],
                    'created' => time(), 'expires' => self::years((int) $foreign['expires'], 1), 'years' => 1, 'locked' => self::TLDS[$tld]['lock'], 'pending' => false, 'nameservers' => [], 'contacts' => [],
                    'privacy' => ['id' => (string) (7_100_000 + $this->scenario->state()->increment('ids', 'privacy')), 'enabled' => false, 'email' => '', 'forward' => ''],
                    'attributes' => [], 'charged' => $transfer['charged'], 'lock_until' => null,
                ]);

                return 'completed';
            }

            throw new \InvalidArgumentException($name.' has no transfer waiting.');
        });
    }

    public function setTransferStatus(string $name, int $statusId): int
    {
        return $this->scenario->state()->transaction(function () use ($name, $statusId): int {
            foreach ($this->scenario->state()->all('transfers') as $transfer) {
                if ($transfer['domain'] === $name) {
                    $this->scenario->state()->put('transfers', $transfer['id'], [...$transfer, 'status_id' => $statusId, 'status_at' => time()]);

                    return $statusId;
                }
            }

            throw new \InvalidArgumentException($name.' has no transfer.');
        });
    }

    public function expire(string $name, int $daysAgo): int
    {
        return $this->scenario->state()->transaction(function () use ($name, $daysAgo): int {
            $domain = $this->scenario->state()->get('domains', $name);

            if (! is_array($domain) || $domain['pending']) {
                throw new \InvalidArgumentException($name.' is not in the account.');
            }

            $this->scenario->state()->put('domains', $name, [...$domain, 'expires' => time() - $daysAgo * 86_400]);

            return $daysAgo;
        });
    }

    // ----------------------------------------------------------------- state helpers

    /**
     * The domain when it is in the account and not pending; otherwise the error the API gives: 2016166 for a name held
     * elsewhere (the caller does not own it), 2019166 for one the registry does not know.
     *
     * @return array<string, mixed>|Response
     */
    private function owned(string $command, string $name): array|Response
    {
        $name = strtolower(trim($name));
        $domain = $this->scenario->state()->get('domains', $name);

        if (is_array($domain) && ! $domain['pending']) {
            return $domain;
        }

        return $this->taken($name)
            ? self::error($command, self::E_NOT_OURS, 'The domain '.$name.' is not associated with your account.')
            : self::error($command, self::E_NOT_FOUND, 'The domain '.$name.' was not found.');
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function ownedBySldTld(string $command, string $sld, string $tld): array|Response
    {
        $domain = $this->owned($command, $sld.'.'.$tld);

        // The TLD sent must be the name's whole extension: `co.uk`, not `uk` with `example.co` as the label.
        return $domain instanceof Response || self::split(strtolower($sld.'.'.$tld))[1] === strtolower($tld) ? $domain : self::error($command, self::E_INVALID_PARAMETER, 'TLD does not match the extension of '.$sld.'.'.$tld.'.');
    }

    private function taken(string $name): bool
    {
        return $this->scenario->state()->get('domains', $name) !== null || $this->foreign($name) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function foreign(string $name): ?array
    {
        $foreign = $this->scenario->state()->get('foreign', $name);

        return is_array($foreign) ? $foreign : null;
    }

    private function charge(string $command, int $cents): ?Response
    {
        $balance = (int) $this->scenario->state()->get('config', 'balance', 0);

        if ($balance < $cents) {
            return self::error($command, self::E_FUNDS, 'The account balance is too low: '.self::dollars($cents).' USD is needed and '.self::dollars($balance).' USD is available.');
        }

        $this->scenario->state()->put('config', 'balance', $balance - $cents);

        return null;
    }

    private function nextId(string $kind, int $base): string
    {
        return (string) ($base + $this->scenario->state()->increment('ids', $kind));
    }

    // ----------------------------------------------------------------- input checks

    /**
     * @param  array<array-key, mixed>  $input
     */
    private static function invalidName(string $command, string $name, array $input): ?Response
    {
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $name) !== 1) {
            return self::error($command, self::E_INVALID_PARAMETER, 'DomainName is not a valid name; send an internationalised name as punycode.');
        }

        if (str_starts_with($name, 'xn--') && trim((string) ($input['IdnCode'] ?? '')) === '') {
            return self::missing($command, 'IdnCode');
        }

        return null;
    }

    /**
     * @param  list<string>  $nameservers
     */
    private static function invalidNameservers(string $command, array $nameservers): ?Response
    {
        foreach ($nameservers as $nameserver) {
            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $nameserver) !== 1) {
                return self::error($command, self::E_INVALID_PARAMETER, '"'.$nameserver.'" is not a valid name server.');
            }
        }

        return count($nameservers) !== count(array_unique(array_map('strtolower', $nameservers))) ? self::error($command, self::E_INVALID_PARAMETER, 'A name server is listed twice.') : null;
    }

    /**
     * The four roles from `<Role><Field>` parameters (Registrant, Tech, Admin, AuxBilling; all four are required).
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, array<string, string>>|Response
     */
    private static function contacts(string $command, array $input): array|Response
    {
        $contacts = [];

        foreach (self::ROLES as $role) {
            foreach (self::CONTACT_FIELDS as $field) {
                if (trim((string) ($input[$role.$field] ?? '')) === '') {
                    return self::missing($command, $role.$field);
                }

                $contacts[$role][$field] = trim((string) $input[$role.$field]);
            }

            foreach (self::OPTIONAL_CONTACT_FIELDS as $field) {
                $contacts[$role][$field] = trim((string) ($input[$role.$field] ?? ''));
            }

            if (preg_match('/^\+\d{1,3}\.\d{4,14}$/', trim((string) $input[$role.'Phone'])) !== 1) {
                return self::error($command, self::E_INVALID_PARAMETER, $role.'Phone must look like +977.9841234567.');
            }

            if (filter_var(trim((string) $input[$role.'EmailAddress']), FILTER_VALIDATE_EMAIL) === false) {
                return self::error($command, self::E_INVALID_PARAMETER, $role.'EmailAddress is not a valid email address.');
            }

            if (preg_match('/^[A-Za-z]{2}$/', trim((string) $input[$role.'Country'])) !== 1) {
                return self::error($command, self::E_INVALID_PARAMETER, $role.'Country must be a two-letter country code.');
            }
        }

        return $contacts;
    }

    // ----------------------------------------------------------------- shapes

    /**
     * The longest extension this account sells that ends the name: [label, extension], or [null, null].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function split(string $name): array
    {
        $best = null;

        foreach (array_keys(self::TLDS) as $tld) {
            if (str_ends_with($name, '.'.$tld) && ($best === null || strlen($tld) > strlen($best))) {
                $best = $tld;
            }
        }

        return $best === null ? [null, null] : [substr($name, 0, -strlen($best) - 1), $best];
    }

    /**
     * The account's record of a name (a registration waiting for the registry included), for tests.
     *
     * @return array<string, mixed>|null
     */
    public function domain(string $name): ?array
    {
        $domain = $this->scenario->state()->get('domains', strtolower($name));

        return is_array($domain) ? $domain : null;
    }

    public static function premium(string $name): bool
    {
        return str_starts_with($name, 'premium-');
    }

    public static function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function date(int $time): string
    {
        return gmdate('m/d/Y', $time);
    }

    public static function years(int $from, int $years): int
    {
        return (int) strtotime('+'.$years.' years', $from);
    }

    public static function transferText(int $statusId): string
    {
        return self::TRANSFER_STATUSES[$statusId] ?? 'Status '.$statusId;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function attributes(array $attributes): string
    {
        $out = '';

        foreach ($attributes as $name => $value) {
            $out .= ' '.$name.'="'.self::e($value).'"';
        }

        return $out;
    }

    public static function ok(string $command, string $body): Response
    {
        return self::envelope('OK', $command, '<Errors />', '<CommandResponse Type="'.self::e($command).'">'.$body.'</CommandResponse>');
    }

    public static function error(string $command, int $number, string $message): Response
    {
        return self::envelope('ERROR', $command, '<Errors><Error Number="'.$number.'">'.self::e($message).'</Error></Errors>', '');
    }

    public static function missing(mixed $command, string $parameter): Response
    {
        return self::error((string) $command, self::E_MISSING_PARAMETER, 'Parameter '.$parameter.' is missing.');
    }

    private static function envelope(string $status, string $command, string $errors, string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?>'
            .'<ApiResponse Status="'.$status.'" xmlns="'.self::NAMESPACE_URI.'">'.$errors.'<Warnings /><RequestedCommand>'.self::e($command).'</RequestedCommand>'.$body
            .'<Server>SIM-API01</Server><GMTTimeDifference>--5:00</GMTTimeDifference><ExecutionTime>0.01</ExecutionTime></ApiResponse>');
    }
}
