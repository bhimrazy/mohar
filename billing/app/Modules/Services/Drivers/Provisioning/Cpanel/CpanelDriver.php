<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\Provisioning\ProvidesServerSso;
use App\Contracts\Services\Provisioning\ProvidesServiceSso;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\Provisioning\ReconcilesCommands;
use App\Contracts\Services\Provisioning\ReportsUsage;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\ConnectionCheckStep;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\CommandLookup;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\RemoteAccount;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\SsoLink;
use App\Data\Services\Provisioning\UsageRow;
use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\CheckStatus;
use App\Enums\DriverOutcome;
use App\Enums\FieldType;
use App\Enums\ReconcileStatus;
use App\Support\Integrations\OutboundAddressGuard;
use Carbon\CarbonImmutable;
use Closure;

/**
 * cPanel & WHM through WHM API 1 (docs/product/provisioning-cpanel.md). Stateless: every call builds a WhmClient for
 * the server in its context, and nothing is cached or stored here.
 *
 * Every WHM answer maps to one outcome (spec §5): transport failures by WhmClient, `metadata.result=0` by WhmReasons.
 * Create is idempotent: the username is checked first, and an existing account with the service's domain and the
 * connection's owner is taken as ours (§6.2).
 */
class CpanelDriver implements ChangesPackage, ChangesPassword, ListsAccounts, ProvidesFieldOptions, ProvidesServerSso, ProvidesServiceSso, ProvisioningDriver, ReconcilesCommands, ReportsUsage, TestsServerConnection
{
    public const KEY = 'cpanel';

    /** Other usernames tried when the assigned one is taken by an account that is not ours (§3.1). */
    private const MAX_RENAMES = 5;

    /** createacct keeps running after a timeout; an absent account proves nothing before this (§6.2). */
    private const SETTLE_MINUTES = 10;

    private const PAGE_SIZE = 500;

    private const MAX_PAGES = 200;

    private const MIN_TESTED_VERSION = '11.110';

    private const TOKEN_EXPIRY_WARNING_DAYS = 14;

    /**
     * Seconds one operation's calls may take together, inside the host's 60-second command timeout, so staff and
     * client actions stay synchronous. createacct gets what the checks before it left (normally most of its 50 s);
     * if it runs out, the outcome is unknown and the verify sweep settles it (§6).
     */
    private const OPERATION_SECONDS = 55;

    /** Token permissions every lifecycle call needs, by ACL name => what for (§2). */
    private const REQUIRED_ACLS = [
        'basic-whm-functions' => 'version checks',
        'list-accts' => 'account lookups, import and usage',
        'acct-summary' => 'account read-back',
        'create-acct' => 'creating accounts',
        'suspend-acct' => 'suspending and unsuspending',
        'kill-acct' => 'terminating accounts',
        'upgrade-account' => 'changing packages',
        'passwd' => 'changing passwords',
        'list-pkgs' => 'the package list',
        'show-bandwidth' => 'bandwidth usage',
    ];

    private const OPTIONAL_ACLS = [
        'create-user-session' => 'single sign-on will not work (the login buttons stay hidden)',
        'ns-config' => 'the name servers cannot be fetched',
    ];

    /** System accounts and mail aliases cPanel refuses as usernames (§4.1), within the 8-character policy. */
    private const RESERVED_NAMES = [
        'abuse', 'admin', 'all', 'apache', 'bin', 'cpanel', 'cphulkd', 'cpses', 'daemon', 'dovecot', 'exim', 'ftp',
        'games', 'halt', 'help', 'hostmast', 'info', 'lp', 'mail', 'mailman', 'mailnull', 'marketin', 'mysql', 'named',
        'news', 'nobody', 'noc', 'operator', 'postgres', 'postmast', 'root', 'sales', 'security', 'shutdown',
        'support', 'sync', 'sys', 'system', 'tomcat', 'virtfs', 'webmaste', 'whm', 'www', 'wwwdata',
    ];

    /** SSO shortcut targets => [cPanel app name, label] (§7, K7). */
    private const SSO_APPS = [
        'email' => ['Email_Accounts', 'Email accounts'],
        'files' => ['FileManager_Home', 'File Manager'],
        'databases' => ['Database_phpMyAdmin', 'phpMyAdmin'],
        'backups' => ['Backups_Home', 'Backups'],
    ];

    public function __construct(private readonly CertificateInspector $certificates) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'cPanel & WHM';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            requiresServer: true,
            defaultPort: null,
            defaultSecurePort: WhmClient::DEFAULT_PORT,
            productConfigFields: [
                new SettingField('package', 'WHM package', FieldType::Select, required: true, help: 'The package exactly as WHM names it, for example root_basic.', optionsSource: SettingField::OPTIONS_REMOTE),
                new SettingField('dedicated_ip', 'Dedicated IP address', FieldType::Boolean, help: 'Give each account its own IP from the server\'s pool. A configurable option with the module key dedicated_ip turns it on per order.', default: false),
                new SettingField('send_contact_email', 'Send the client\'s email to cPanel', FieldType::Boolean, help: 'cPanel mails quota and security notices to this address.', default: true),
                new SettingField('allow_temporary_domain', 'Allow accounts without a domain', FieldType::Boolean, help: 'Off: an order without a domain is refused. On: WHM assigns a temporary domain.', default: false),
                new SettingField('cpmod', 'cPanel theme', help: 'Leave empty for the server default.'),
                new SettingField('language', 'cPanel language', help: 'A locale code such as en or de. Leave empty for the server default.'),
            ],
            serverFields: [
                new SettingField('login_method', 'Sign in with', FieldType::Select, help: 'An API token can be limited to the permissions this driver needs. Password sign-in fails when WHM requires two-factor authentication.', options: [WhmClient::LOGIN_TOKEN => 'API token (recommended)', WhmClient::LOGIN_PASSWORD => 'Username and password'], default: WhmClient::LOGIN_TOKEN),
                new SettingField('spki_pin', 'Trusted certificate (SPKI SHA-256)', help: 'Set by "Trust this certificate" after Test connection. Empty: the certificate is checked against its authority and the hostname.'),
                new SettingField('sso_enabled', 'Single sign-on into cPanel and WHM', FieldType::Boolean, help: 'Needs the token permission create-user-session. Whoever holds the token can then sign in as any account its owner owns.', default: true),
                new SettingField('keep_dns_on_terminate', 'Keep the DNS zone when an account is terminated', FieldType::Boolean, help: 'A kept zone blocks creating an account for that domain again until it is removed in WHM.', default: false),
                new SettingField('reserved_usernames', 'Extra reserved usernames', FieldType::Text, help: 'One per line. New accounts never get these usernames.'),
            ],
            usernamePolicy: new UsernamePolicy(
                maxLength: 8,
                pattern: '/^[a-z][a-z0-9]*$/',
                reservedPrefixes: ['test'],
                reservedSuffixes: ['assword'],
                reservedNames: self::RESERVED_NAMES,
            ),
            refusedPorts: WhmClient::PLAIN_PORTS,
        );
    }

    public function create(ProvisioningContext $context): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context): DriverResult {
            $username = (string) $context->service->username;
            $domain = $this->domain($context);
            $package = $this->package($context);
            $violation = $username === '' ? UsernamePolicy::INVALID_CHARACTERS : $this->metadata()->usernamePolicy?->violation($username);

            if ($violation !== null) {
                return DriverResult::fail("The username \"{$username}\" does not meet cPanel's username rules ({$violation}); set another one on the service.");
            }

            if ($domain === '' && ! $this->flag($context->productConfig, 'allow_temporary_domain')) {
                return DriverResult::fail('A domain is required to create a cPanel account.');
            }

            if ($package === '') {
                return DriverResult::fail('The product has no WHM package set.');
            }

            $this->assertPackage($client, $server, $package);
            $claim = $this->claimUsername($client, $server, $username, $domain);

            if ($claim['existing'] !== null) {
                return $this->created($client, $context, $claim['username'], $claim['existing'], 'The account already existed on the server for this service.');
            }

            $params = array_filter([
                'username' => $claim['username'],
                'domain' => $domain,
                'plan' => $package,
                'password' => (string) $context->service->password,
                'contactemail' => $this->flag($context->productConfig, 'send_contact_email', true) ? $context->client->email : '',
                'ip' => $this->wantsDedicatedIp($context) ? 'y' : '',
                'showpass' => 'n',
                'forcedns' => 0,
                'cpmod' => trim((string) ($context->productConfig['cpmod'] ?? '')),
                'language' => trim((string) ($context->productConfig['language'] ?? '')),
            ], fn (mixed $value): bool => $value !== '');

            $response = $client->write('createacct', $params);

            if (! $response->ok) {
                throw $this->refusal($response, $server, $claim['username'], $domain, $package);
            }

            return $this->created($client, $context, $claim['username'], null, 'Account created.');
        });
    }

    public function suspend(ProvisioningContext $context, string $reason): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context, $reason): DriverResult {
            $username = (string) $context->service->username;
            $text = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason)), 0, 100);
            $response = $client->write('suspendacct', ['user' => $username, 'reason' => $text, 'disallowun' => 0]);

            if ($response->ok) {
                return DriverResult::ok('Account suspended.');
            }

            $this->refuseOnAccessProblem($response, $server);

            if ((int) ($this->summary($client, $username)['suspended'] ?? 0) === 1) {
                return DriverResult::ok('The account was already suspended.');
            }

            throw $this->refusal($response, $server, $username);
        });
    }

    public function unsuspend(ProvisioningContext $context): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context): DriverResult {
            $username = (string) $context->service->username;
            $response = $client->write('unsuspendacct', ['user' => $username]);

            if ($response->ok) {
                return DriverResult::ok('Account unsuspended.');
            }

            $this->refuseOnAccessProblem($response, $server);
            $account = $this->summary($client, $username);

            if ($account !== null && (int) ($account['suspended'] ?? 0) === 0) {
                return DriverResult::ok('The account was not suspended.');
            }

            if ((int) ($account['is_locked'] ?? 0) === 1) {
                return DriverResult::fail('The account was suspended by the server administrator; only they can unsuspend it.');
            }

            throw $this->refusal($response, $server, $username);
        });
    }

    public function terminate(ProvisioningContext $context): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context): DriverResult {
            $username = (string) $context->service->username;
            $response = $client->write('removeacct', ['username' => $username, 'keepdns' => $this->flag($server->settings, 'keep_dns_on_terminate') ? 1 : 0]);

            if ($response->ok) {
                return DriverResult::ok('Account removed from the server.');
            }

            $this->refuseOnAccessProblem($response, $server);

            if ($this->findAccount($client, $server, 'user', $username) === null) {
                return DriverResult::ok('The account was not on the server.');
            }

            throw $this->refusal($response, $server, $username);
        });
    }

    public function changePackage(ProvisioningContext $context, PreviousPackage $previous): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context): DriverResult {
            $username = (string) $context->service->username;
            $package = $this->package($context);

            if ($package === '') {
                return DriverResult::fail('The product has no WHM package set.');
            }

            $account = $this->summary($client, $username) ?? throw WhmFailure::rejected("The account {$username} is not on {$server->name}.");

            if (($account['plan'] ?? null) === $package) {
                return DriverResult::ok("The account already uses package {$package}.");
            }

            $this->assertPackage($client, $server, $package);
            $response = $client->write('changepackage', ['user' => $username, 'pkg' => $package]);

            if (! $response->ok) {
                throw $this->refusal($response, $server, $username, package: $package);
            }

            return DriverResult::ok("Package changed to {$package}.", ['driver_data' => ['plan' => $package]]);
        });
    }

    public function changePassword(ProvisioningContext $context, #[\SensitiveParameter] string $newPassword): DriverResult
    {
        return $this->run($context->server, function (WhmClient $client, ServerConnection $server) use ($context, $newPassword): DriverResult {
            $username = (string) $context->service->username;
            // db_pass_update keeps the MySQL password in step, so database logins match the new password.
            $response = $client->write('passwd', ['user' => $username, 'password' => $newPassword, 'db_pass_update' => 1]);

            if (! $response->ok) {
                throw $this->refusal($response, $server, $username);
            }

            return DriverResult::ok('Password changed.');
        });
    }

    /**
     * @throws WhmFailure
     */
    public function usage(ServerConnection $server): array
    {
        $client = $this->client($server);
        $accounts = $this->allAccounts($client, $server, ['want' => 'user,diskused,disklimit']);
        $now = CarbonImmutable::now();
        $bandwidth = $client->read('showbw', ['month' => $now->month, 'year' => $now->year]);

        if (! $bandwidth->ok) {
            throw $this->refusal($bandwidth, $server);
        }

        $traffic = [];

        foreach ($bandwidth->rows('acct') as $row) {
            $traffic[strtolower((string) ($row['user'] ?? ''))] = $row;
        }

        $rows = [];

        foreach ($accounts as $account) {
            $username = strtolower((string) ($account['user'] ?? ''));

            if ($username === '') {
                continue;
            }

            $limit = $traffic[$username]['limit'] ?? null;
            $rows[$username] = new UsageRow(
                diskUsedMb: $this->megabytes($account['diskused'] ?? null) ?? 0,
                diskLimitMb: $this->megabytes($account['disklimit'] ?? null, limit: true),
                bandwidthUsedMb: self::bytesToMegabytes($traffic[$username]['totalbytes'] ?? 0) ?? 0,
                bandwidthLimitMb: self::bytesToMegabytes($limit),
            );
        }

        return $rows;
    }

    /**
     * @throws WhmFailure
     */
    public function listAccounts(ServerConnection $server): array
    {
        return array_map($this->remoteAccount(...), $this->allAccounts($this->client($server), $server));
    }

    /**
     * @throws WhmFailure
     */
    public function fieldOptions(ServerConnection $server, string $fieldKey): array
    {
        if ($fieldKey !== 'package') {
            return [];
        }

        $options = [];

        foreach ($this->packages($this->client($server), $server) as $package) {
            $name = (string) ($package['name'] ?? '');
            $options[$name] = sprintf('%s (%s disk, %s bandwidth)', $name, $this->sizeLabel($package['QUOTA'] ?? null), $this->sizeLabel($package['BWLIMIT'] ?? null));
        }

        return $options;
    }

    public function ssoTargets(): array
    {
        return array_map(fn (array $app): string => $app[1], self::SSO_APPS);
    }

    /**
     * @throws WhmFailure
     */
    public function serviceSsoLink(ProvisioningContext $context, ?string $target = null): SsoLink
    {
        $server = $context->server ?? throw WhmFailure::rejected('cPanel services need a server.', WhmFailure::CONFIG);

        return $this->session($server, array_filter([
            'user' => (string) $context->service->username,
            'service' => 'cpaneld',
            'preferred_domain' => $server->hostname,
            'app' => self::SSO_APPS[(string) $target][0] ?? '',
        ], fn (string $value): bool => $value !== ''));
    }

    /**
     * @throws WhmFailure
     */
    public function serverSsoLink(ServerConnection $server): SsoLink
    {
        return $this->session($server, ['user' => WhmClient::connectionUser($server), 'service' => 'whostmgrd']);
    }

    public function reconcile(ProvisioningContext $context, string $command, CarbonImmutable $attemptedAt): CommandLookup
    {
        $server = $context->server;

        if ($server === null) {
            return new CommandLookup(ReconcileStatus::Unknown, message: 'The service has no server to check.');
        }

        $client = $this->client($server);
        $username = (string) $context->service->username;

        try {
            if ($command === 'create') {
                return $this->reconcileCreate($client, $server, $username, $this->domain($context), $attemptedAt);
            }

            if ($command === 'terminate') {
                $account = $this->findAccount($client, $server, 'user', $username);

                return $account === null
                    ? new CommandLookup(ReconcileStatus::Executed, message: 'The account is no longer on the server.')
                    : new CommandLookup(ReconcileStatus::NotExecuted, $this->remoteAccount($account), 'The account is still on the server.');
            }

            if (! in_array($command, ['suspend', 'unsuspend', 'change_package'], true)) {
                return new CommandLookup(ReconcileStatus::Unknown, message: "cPanel cannot show whether {$command} ran; it is safe to run it again.");
            }

            $account = $this->summary($client, $username);

            if ($account === null) {
                return new CommandLookup(ReconcileStatus::Conflict, message: "The account {$username} is not on {$server->name}.");
            }

            $done = match ($command) {
                'suspend' => (int) ($account['suspended'] ?? 0) === 1,
                'unsuspend' => (int) ($account['suspended'] ?? 0) === 0,
                default => ($account['plan'] ?? null) === $this->package($context),
            };

            return new CommandLookup($done ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $this->remoteAccount($account));
        } catch (WhmFailure $failure) {
            return new CommandLookup(ReconcileStatus::Unknown, message: $failure->getMessage());
        }
    }

    public function testConnection(ServerConnection $server): ConnectionCheck
    {
        $client = $this->client($server);
        $steps = [];
        $details = [];

        try {
            $address = $client->address();
        } catch (WhmFailure $failure) {
            return $this->check([new ConnectionCheckStep('Server address', CheckStatus::Fail, $failure->getMessage())], []);
        }

        try {
            $version = $client->read('version');
        } catch (WhmFailure $failure) {
            if (in_array($failure->kind, [WhmFailure::TLS, WhmFailure::PIN], true)) {
                $certificate = $this->certificates->inspect($address, $client->host());

                foreach ($certificate ?? [] as $key => $value) {
                    $details["certificate_{$key}"] = $value;
                }

                $steps[] = new ConnectionCheckStep('Address and certificate', CheckStatus::Fail, $failure->getMessage().($certificate === null ? ' The certificate could not be read.' : ''));

                return $this->check($steps, $details);
            }

            if ($failure->kind === WhmFailure::TRANSPORT) {
                return $this->check([new ConnectionCheckStep('Address and certificate', CheckStatus::Fail, $failure->getMessage())], $details);
            }

            $steps[] = new ConnectionCheckStep('Address and certificate', CheckStatus::Pass, "{$client->host()} ({$address->ip}) answered over TLS.");
            $steps[] = new ConnectionCheckStep('Sign in', CheckStatus::Fail, $failure->getMessage());

            return $this->check($steps, $details);
        }

        $steps[] = new ConnectionCheckStep('Address and certificate', CheckStatus::Pass, "{$client->host()} ({$address->ip}) answered over TLS".(WhmClient::pin($server) !== null ? ' with the trusted certificate.' : ' with a valid certificate.'));

        if (! $version->ok) {
            $steps[] = new ConnectionCheckStep('Sign in', CheckStatus::Fail, $this->refusal($version, $server)->getMessage());

            return $this->check($steps, $details);
        }

        $details['version'] = (string) ($version->data['version'] ?? '');
        $steps[] = version_compare($details['version'], self::MIN_TESTED_VERSION, '>=')
            ? new ConnectionCheckStep('Sign in', CheckStatus::Pass, 'Signed in as '.WhmClient::connectionUser($server)." to WHM {$details['version']}.")
            : new ConnectionCheckStep('Sign in', CheckStatus::Warn, "Signed in, but WHM {$details['version']} is older than ".self::MIN_TESTED_VERSION.' and untested.');

        $privileges = $this->privileges($client);
        $root = $privileges !== null ? isset($privileges['all']) : WhmClient::connectionUser($server) === 'root';
        $steps[] = $this->permissionStep($client, $server, $privileges, $details);
        $steps[] = $this->accountKindStep($client, $server, $root, $details);
        $steps[] = $this->packageStep($client, $server);
        $steps[] = $this->nameserverStep($client, $details);

        return $this->check($steps, $details);
    }

    /**
     * Run one lifecycle operation: map a WhmFailure to its outcome, and attach the operation's request and response
     * log (secret parameters never enter it; the host redacts the rest).
     *
     * @param  Closure(WhmClient, ServerConnection): DriverResult  $operation
     */
    private function run(?ServerConnection $server, Closure $operation): DriverResult
    {
        if ($server === null) {
            return DriverResult::fail('cPanel services need a server.');
        }

        $client = new WhmClient($server, app(OutboundAddressGuard::class), microtime(true) + self::OPERATION_SECONDS);

        try {
            $result = $operation($client, $server);
        } catch (WhmFailure $failure) {
            $result = match ($failure->outcome) {
                DriverOutcome::Unknown => DriverResult::unknown($failure->getMessage()),
                DriverOutcome::Retryable => DriverResult::fail($failure->getMessage(), retryable: true),
                default => DriverResult::fail($failure->getMessage()),
            };
        }

        return new DriverResult($result->success, $result->message, $result->retryable, $result->data, $client->requestLog(), $client->responseLog(), $result->outcomeUnknown);
    }

    private function client(ServerConnection $server): WhmClient
    {
        return new WhmClient($server, app(OutboundAddressGuard::class));
    }

    /**
     * @param  array<string, mixed>|null  $account  the account already found on the server, or null to read it back
     */
    private function created(WhmClient $client, ProvisioningContext $context, string $username, ?array $account, string $message): DriverResult
    {
        if ($account === null) {
            try {
                $account = $this->summary($client, $username);
            } catch (WhmFailure) {
                // A failed read-back still completes the create; the next usage sync fills in the details.
            }
        }

        $created = isset($account['unix_startdate']) && is_numeric($account['unix_startdate']) ? CarbonImmutable::createFromTimestamp((int) $account['unix_startdate'])->toIso8601String() : null;
        $data = ['driver_data' => array_filter([
            'owner' => $account['owner'] ?? null,
            'plan' => $account['plan'] ?? null,
            'ip' => $account['ip'] ?? null,
            'created_at' => $created,
        ], fn (mixed $value): bool => $value !== null && $value !== '')];

        if ($username !== $context->service->username) {
            $data['username'] = $username;
        }

        if ($this->wantsDedicatedIp($context) && is_string($account['ip'] ?? null) && $account['ip'] !== '') {
            $data['dedicated_ip'] = $account['ip'];
        }

        return DriverResult::ok($message, $data);
    }

    /**
     * The username to create (the assigned one, or the next free variant), or an existing account that is ours.
     *
     * @return array{username: string, existing: array<string, mixed>|null}
     *
     * @throws WhmFailure
     */
    private function claimUsername(WhmClient $client, ServerConnection $server, string $username, string $domain): array
    {
        $policy = $this->metadata()->usernamePolicy ?? new UsernamePolicy;
        $reserved = $this->serverReservedNames($server);
        $base = rtrim($username, '0123456789') ?: $username;
        $candidates = [$username];

        for ($suffix = 1; count($candidates) <= self::MAX_RENAMES; $suffix++) {
            $candidate = substr($base, 0, $policy->maxLength - strlen((string) $suffix)).$suffix;

            if (! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if ($policy->violation($candidate) !== null || in_array($candidate, $reserved, true)) {
                continue;
            }

            $check = $client->read('verify_new_username', ['user' => $candidate]);

            if ($check->ok) {
                return ['username' => $candidate, 'existing' => null];
            }

            $this->refuseOnAccessProblem($check, $server);
            $existing = $this->findAccount($client, $server, 'user', $candidate);

            if ($existing !== null && $this->isOurs($existing, $domain, $server)) {
                return ['username' => $candidate, 'existing' => $existing];
            }
        }

        throw WhmFailure::rejected("No free username near \"{$username}\" on {$server->name} after ".self::MAX_RENAMES.' tries; set one on the service.');
    }

    /**
     * @throws WhmFailure
     */
    private function reconcileCreate(WhmClient $client, ServerConnection $server, string $username, string $domain, CarbonImmutable $attemptedAt): CommandLookup
    {
        $account = $this->findAccount($client, $server, 'user', $username);

        if ($account !== null) {
            return $this->isOurs($account, $domain, $server)
                ? new CommandLookup(ReconcileStatus::Executed, $this->remoteAccount($account), 'The account is on the server.')
                : new CommandLookup(ReconcileStatus::Conflict, $this->remoteAccount($account), sprintf('The username %s on %s belongs to %s with the domain %s, not to this service.', $username, $server->name, $account['owner'] ?? 'another owner', $account['domain'] ?? 'unknown'));
        }

        // The account may exist under a renamed username (the create picked the next free name before it timed out).
        $byDomain = $domain === '' ? null : $this->findAccount($client, $server, 'domain', $domain);

        if ($byDomain !== null) {
            return $this->isOurs($byDomain, $domain, $server)
                ? new CommandLookup(ReconcileStatus::Executed, $this->remoteAccount($byDomain), "The account is on the server as {$byDomain['user']}.")
                : new CommandLookup(ReconcileStatus::Conflict, $this->remoteAccount($byDomain), sprintf('The domain %s is on %s under the account %s, owned by %s.', $domain, $server->name, $byDomain['user'] ?? 'unknown', $byDomain['owner'] ?? 'another owner'));
        }

        return $attemptedAt->addMinutes(self::SETTLE_MINUTES)->isFuture()
            ? new CommandLookup(ReconcileStatus::InProgress, message: 'Not on the server yet; WHM may still be creating it.')
            : new CommandLookup(ReconcileStatus::NotExecuted, message: 'The account is not on the server.');
    }

    /**
     * @param  array<string, mixed>  $account  a listaccts or accountsummary row
     */
    private function isOurs(array $account, string $domain, ServerConnection $server): bool
    {
        return ($domain === '' || strtolower((string) ($account['domain'] ?? '')) === $domain)
            && ($account['owner'] ?? null) === WhmClient::connectionUser($server);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws WhmFailure
     */
    private function findAccount(WhmClient $client, ServerConnection $server, string $field, string $value): ?array
    {
        $response = $client->read('listaccts', ['searchtype' => $field, 'searchmethod' => 'exact', 'search' => $value]);

        if (! $response->ok) {
            throw $this->refusal($response, $server);
        }

        foreach ($response->rows('acct') as $row) {
            if (strtolower((string) ($row[$field === 'user' ? 'user' : 'domain'] ?? '')) === strtolower($value)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The account's summary, or null when WHM says it does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws WhmFailure
     */
    private function summary(WhmClient $client, string $username): ?array
    {
        $response = $client->read('accountsummary', ['user' => $username]);

        return $response->ok ? ($response->rows('acct')[0] ?? null) : null;
    }

    /**
     * Every account the connection can see, in pages (`api.chunk.*`).
     *
     * @param  array<string, scalar>  $params
     * @return list<array<string, mixed>>
     *
     * @throws WhmFailure
     */
    private function allAccounts(WhmClient $client, ServerConnection $server, array $params = []): array
    {
        $rows = [];
        $start = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $client->read('listaccts', [...$params, 'api.chunk.enable' => 1, 'api.chunk.verbose' => 1, 'api.chunk.size' => self::PAGE_SIZE, 'api.chunk.start' => $start]);

            if (! $response->ok) {
                throw $this->refusal($response, $server);
            }

            $batch = $response->rows('acct');
            array_push($rows, ...$batch);
            $start += count($batch);
            $total = (int) ($response->metadata['chunk']['records'] ?? 0);

            if ($batch === [] || ($total > 0 ? $start > $total : count($batch) < self::PAGE_SIZE)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws WhmFailure
     */
    private function packages(WhmClient $client, ServerConnection $server): array
    {
        $response = $client->read('listpkgs', ['want' => 'creatable']);

        if (! $response->ok) {
            throw $this->refusal($response, $server);
        }

        return array_values(array_filter($response->rows('pkg'), fn (array $package): bool => (string) ($package['name'] ?? '') !== ''));
    }

    /**
     * A package the connection cannot assign is rejected before any mutating call (§4.2). Not cached: the driver
     * holds no state, and a fresh answer is right before a create.
     *
     * @throws WhmFailure
     */
    private function assertPackage(WhmClient $client, ServerConnection $server, string $package): void
    {
        $names = array_map(fn (array $row): string => (string) $row['name'], $this->packages($client, $server));

        if (! in_array($package, $names, true)) {
            throw WhmFailure::rejected($this->packageMessage($package, $server));
        }
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws WhmFailure
     */
    private function session(ServerConnection $server, array $params): SsoLink
    {
        if (! $this->flag($server->settings, 'sso_enabled', true)) {
            throw WhmFailure::rejected('Single sign-on is turned off for this server.', WhmFailure::CONFIG);
        }

        $response = $this->client($server)->write('create_user_session', $params);

        if (! $response->ok) {
            throw $this->refusal($response, $server, $params['user']);
        }

        $url = (string) ($response->data['url'] ?? '');

        if (! str_starts_with($url, 'https://')) {
            throw WhmFailure::rejected('WHM did not return a login link.');
        }

        $expires = $response->data['expires'] ?? null;

        return new SsoLink($url, is_numeric($expires) ? CarbonImmutable::createFromTimestamp((int) $expires) : null);
    }

    /**
     * Credential, ACL and licence refusals are reported as such, never second-guessed by a lookup (a reseller token
     * without an ACL would otherwise see "no such account" and report success).
     *
     * @throws WhmFailure
     */
    private function refuseOnAccessProblem(WhmResponse $response, ServerConnection $server): void
    {
        if (in_array(WhmReasons::classify($response->reason), [WhmReasons::AUTH, WhmReasons::LICENCE], true)) {
            throw $this->refusal($response, $server);
        }
    }

    /**
     * `metadata.result=0`: WHM validates before acting, so the change did not happen (rejected). The message is ours;
     * the raw reason stays in the module log.
     */
    private function refusal(WhmResponse $response, ServerConnection $server, ?string $username = null, ?string $domain = null, ?string $package = null): WhmFailure
    {
        $kind = WhmReasons::classify($response->reason);

        return WhmFailure::rejected(match ($kind) {
            WhmReasons::LICENCE => "{$server->name} cannot do this: its cPanel licence refused the request. Fix the licence in WHM.",
            WhmReasons::AUTH => 'WHM refused the request: the API token (or password) is wrong, or it lacks the permission (ACL) this action needs.',
            WhmReasons::RESERVED => "cPanel reserves the username \"{$username}\"; set another username on the service.",
            WhmReasons::DOMAIN_EXISTS => "Domain already on server: {$domain} exists on {$server->name}, often as a DNS zone kept by an earlier termination. Remove it in WHM or use another domain.",
            WhmReasons::USERNAME_TAKEN => "The username \"{$username}\" is already taken on {$server->name}.",
            WhmReasons::PACKAGE => $this->packageMessage((string) $package, $server),
            WhmReasons::WEAK_PASSWORD => 'The server rejected this password: too weak. Choose a longer password that mixes letters, digits and symbols.',
            WhmReasons::THEME => 'The server does not accept the cPanel theme or language set on the product.',
            WhmReasons::NO_ACCOUNT => "The account \"{$username}\" is not on {$server->name}.",
            default => "WHM refused {$response->function}; the module log has the server's reason.",
        }, $kind === WhmReasons::AUTH ? WhmFailure::AUTH : WhmFailure::REFUSED);
    }

    private function packageMessage(string $package, ServerConnection $server): string
    {
        return sprintf('Package %s is not available to %s on %s.', $package, WhmClient::connectionUser($server), $server->name);
    }

    /**
     * The connection's privileges from `myprivs` (ACL => true), or null when it cannot be read.
     *
     * @return array<string, true>|null
     */
    private function privileges(WhmClient $client): ?array
    {
        try {
            $response = $client->read('myprivs');
        } catch (WhmFailure) {
            return null;
        }

        $privileges = $response->ok ? ($response->rows('privileges')[0] ?? null) : null;

        return $privileges === null ? null : array_fill_keys(array_keys(array_filter($privileges, fn (mixed $value): bool => (int) $value === 1)), true);
    }

    /**
     * @param  array<string, true>|null  $privileges
     * @param  array<string, string>  $details
     */
    private function permissionStep(WhmClient $client, ServerConnection $server, ?array $privileges, array &$details): ConnectionCheckStep
    {
        $acls = $privileges;
        $expiry = null;

        if (WhmClient::loginMethod($server) === WhmClient::LOGIN_TOKEN) {
            try {
                $token = $client->read('api_token_get_details');

                if ($token->ok) {
                    $list = $token->data['acls'] ?? [];
                    $acls = array_fill_keys(array_map('strval', array_is_list((array) $list) ? (array) $list : array_keys(array_filter((array) $list))), true);
                    $expiry = is_numeric($token->data['expires_at'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $token->data['expires_at']) : null;
                }
            } catch (WhmFailure) {
                // The token cannot read itself; myprivs is the fallback.
            }
        }

        if ($acls === null) {
            return new ConnectionCheckStep('Permissions', CheckStatus::Warn, 'The permissions could not be read; actions may fail with "access denied".');
        }

        $all = isset($acls['all']);
        $missing = $all ? [] : array_diff_key(self::REQUIRED_ACLS, $acls);
        $optional = $all ? [] : array_diff_key(self::OPTIONAL_ACLS, $acls);
        $details['sso_available'] = isset($optional['create-user-session']) ? 'no' : 'yes';

        if ($expiry !== null) {
            $details['token_expires_at'] = $expiry->toIso8601String();
        }

        if ($missing !== []) {
            return new ConnectionCheckStep('Permissions', CheckStatus::Fail, 'The token lacks: '.implode('; ', array_map(fn (string $acl, string $use): string => "{$acl} ({$use})", array_keys($missing), $missing)).'.');
        }

        $warnings = array_map(fn (string $acl, string $effect): string => "Missing {$acl}, so {$effect}.", array_keys($optional), $optional);

        if ($expiry !== null && $expiry->isBefore(CarbonImmutable::now()->addDays(self::TOKEN_EXPIRY_WARNING_DAYS))) {
            $warnings[] = 'The token expires on '.$expiry->toDateString().'.';
        }

        return $warnings === []
            ? new ConnectionCheckStep('Permissions', CheckStatus::Pass, $all ? 'Full access.' : 'Every permission the driver needs is granted.')
            : new ConnectionCheckStep('Permissions', CheckStatus::Warn, implode(' ', $warnings));
    }

    /**
     * @param  array<string, string>  $details
     */
    private function accountKindStep(WhmClient $client, ServerConnection $server, bool $root, array &$details): ConnectionCheckStep
    {
        $details['account_kind'] = $root ? 'root' : 'reseller';

        if ($root) {
            return new ConnectionCheckStep('Account kind', CheckStatus::Pass, 'Root connection: every account on the server is visible.');
        }

        try {
            $counts = $client->read('acctcounts', ['user' => WhmClient::connectionUser($server)]);
            $reseller = is_array($counts->data['reseller'] ?? null) ? $counts->data['reseller'] : [];
            $limit = isset($reseller['limit']) && (string) $reseller['limit'] !== '' ? (string) $reseller['limit'] : 'no limit';

            return new ConnectionCheckStep('Account kind', CheckStatus::Pass, sprintf('Reseller connection: %d active, %d suspended, limit %s. Only accounts it owns are visible.', (int) ($reseller['active'] ?? 0), (int) ($reseller['suspended'] ?? 0), $limit));
        } catch (WhmFailure) {
            return new ConnectionCheckStep('Account kind', CheckStatus::Pass, 'Reseller connection: only accounts it owns are visible.');
        }
    }

    private function packageStep(WhmClient $client, ServerConnection $server): ConnectionCheckStep
    {
        try {
            $count = count($this->packages($client, $server));
        } catch (WhmFailure $failure) {
            return new ConnectionCheckStep('Packages', CheckStatus::Fail, $failure->getMessage());
        }

        return $count === 0
            ? new ConnectionCheckStep('Packages', CheckStatus::Warn, 'There are no packages this user can assign.')
            : new ConnectionCheckStep('Packages', CheckStatus::Pass, "{$count} packages can be assigned.");
    }

    /**
     * @param  array<string, string>  $details
     */
    private function nameserverStep(WhmClient $client, array &$details): ConnectionCheckStep
    {
        try {
            $response = $client->read('get_nameserver_config');
        } catch (WhmFailure $failure) {
            return new ConnectionCheckStep('Name servers', CheckStatus::Warn, $failure->getMessage());
        }

        $nameservers = array_values(array_filter(array_map('strval', (array) ($response->data['nameservers'] ?? []))));

        if (! $response->ok || $nameservers === []) {
            return new ConnectionCheckStep('Name servers', CheckStatus::Warn, 'WHM did not report name servers.');
        }

        $details['nameservers'] = implode(', ', $nameservers);

        return new ConnectionCheckStep('Name servers', CheckStatus::Pass, 'WHM uses '.$details['nameservers'].'.');
    }

    /**
     * @param  list<ConnectionCheckStep>  $steps
     * @param  array<string, string>  $details
     */
    private function check(array $steps, array $details): ConnectionCheck
    {
        $failed = array_values(array_filter($steps, fn (ConnectionCheckStep $step): bool => $step->status === CheckStatus::Fail));
        $message = $failed === []
            ? 'Connected'.(isset($details['version']) ? " to WHM {$details['version']}" : '').(isset($details['account_kind']) ? " ({$details['account_kind']})" : '').'.'
            : "{$failed[0]->label}: {$failed[0]->detail}";

        return new ConnectionCheck($failed === [], $message, $steps, $details);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function remoteAccount(array $row): RemoteAccount
    {
        $reason = trim((string) ($row['suspendreason'] ?? ''));

        return new RemoteAccount(
            username: (string) ($row['user'] ?? ''),
            domain: isset($row['domain']) ? strtolower((string) $row['domain']) : null,
            plan: isset($row['plan']) ? (string) $row['plan'] : null,
            owner: isset($row['owner']) ? (string) $row['owner'] : null,
            email: isset($row['email']) && str_contains((string) $row['email'], '@') ? strtolower((string) $row['email']) : null,
            ip: isset($row['ip']) ? (string) $row['ip'] : null,
            suspended: (int) ($row['suspended'] ?? 0) === 1,
            suspendReason: $reason !== '' && strtolower($reason) !== 'not suspended' ? $reason : null,
            createdAt: is_numeric($row['unix_startdate'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $row['unix_startdate']) : null,
            diskUsedMb: $this->megabytes($row['diskused'] ?? null),
            diskLimitMb: $this->megabytes($row['disklimit'] ?? null, limit: true),
        );
    }

    private function domain(ProvisioningContext $context): string
    {
        return strtolower(trim((string) $context->service->domain));
    }

    private function package(ProvisioningContext $context): string
    {
        return trim((string) ($context->productConfig['package'] ?? ''));
    }

    private function wantsDedicatedIp(ProvisioningContext $context): bool
    {
        return $this->flag($context->productConfig, 'dedicated_ip') || $this->flag($context->configOptions, 'dedicated_ip');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function flag(array $values, string $key, bool $default = false): bool
    {
        $value = $values[$key] ?? null;

        return $value === null || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private function serverReservedNames(ServerConnection $server): array
    {
        return array_values(array_filter(array_map(fn (string $line): string => strtolower(trim($line)), preg_split('/[\s,]+/', (string) ($server->settings['reserved_usernames'] ?? '')) ?: [])));
    }

    /**
     * WHM sizes in MiB: "14M", "14", or "unlimited" (null). A zero limit is unlimited too.
     */
    private function megabytes(mixed $value, bool $limit = false): ?int
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || $value === 'unlimited' || ! is_numeric(rtrim($value, 'm'))) {
            return null;
        }

        $megabytes = (int) round((float) rtrim($value, 'm'));

        return $limit && $megabytes === 0 ? null : $megabytes;
    }

    /**
     * Bytes to MiB, rounded half-up; 0, "unlimited" or nothing is null (an unlimited limit, or no traffic yet).
     */
    private static function bytesToMegabytes(mixed $bytes): ?int
    {
        return is_numeric($bytes) && (int) $bytes > 0 ? (int) round((int) $bytes / 1_048_576) : null;
    }

    private function sizeLabel(mixed $megabytes): string
    {
        $value = $this->megabytes($megabytes, limit: true);

        return match (true) {
            $value === null => 'unlimited',
            $value >= 1024 => rtrim(rtrim(number_format($value / 1024, 1), '0'), '.').' GB',
            default => "{$value} MB",
        };
    }
}
