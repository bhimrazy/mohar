<?php

namespace Mohar\Simulators\Providers\Whm;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Kernel\StateStore;

/**
 * WHM API 1 (`/json-api/<function>?api.version=1`) for the functions the cPanel driver calls, over the scenario state:
 *
 * - `accounts`: user => the listaccts row;
 * - `packages`: name => {name, QUOTA, BWLIMIT, MAXADDON, owner};
 * - `users`: root and resellers, with their privileges, package grants and account limit;
 * - `tokens`: sha256(token) => {user, name, acls, ...};
 * - `zones`: DNS zones (a kept zone blocks its domain);
 * - `sessions`: login links;
 * - `config`: version, name servers, licence, IPs, themes.
 *
 * Answers follow the reference's envelope (`metadata.result`, `reason`, `command`, `version`, then `data`). Every check
 * happens before anything changes, so a refusal (`result: 0`) never leaves a partial change, as on a real server.
 */
final class WhmApi
{
    /** Function => the ACL it needs (null: any signed-in user). */
    public const FUNCTIONS = [
        'version' => 'basic-whm-functions',
        'myprivs' => null,
        'api_token_get_details' => null,
        'acctcounts' => 'list-accts',
        'listaccts' => 'list-accts',
        'accountsummary' => 'acct-summary',
        'listpkgs' => 'list-pkgs',
        'showbw' => 'show-bandwidth',
        'get_nameserver_config' => 'ns-config',
        'verify_new_username' => 'create-acct',
        'createacct' => 'create-acct',
        'suspendacct' => 'suspend-acct',
        'unsuspendacct' => 'suspend-acct',
        'removeacct' => 'kill-acct',
        'changepackage' => 'upgrade-account',
        'passwd' => 'passwd',
        'create_user_session' => 'create-user-session',
    ];

    public const SESSION_SECONDS = 900;

    private readonly StateStore $state;

    public function __construct(private readonly Scenario $scenario)
    {
        $this->state = $scenario->state();
    }

    public function handle(string $function, Request $request): Response
    {
        $input = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $request->input());

        if (($input['api.version'] ?? null) !== '1') {
            return $this->answer($function, false, 'This server answers WHM API 1 only: add api.version=1 to the request.');
        }

        $actor = $this->authenticate($request);

        if (! is_array($actor)) {
            return new Response(401, ['Content-Type' => 'text/html'], '<!doctype html><title>Access denied</title><p>'.($actor ?? 'Access denied').'</p>');
        }

        if (! array_key_exists($function, self::FUNCTIONS)) {
            return $this->answer($function, false, "The function “{$function}” is not part of WHM API 1.");
        }

        $acl = self::FUNCTIONS[$function];

        if ($acl !== null && ! $this->allowed($actor, $acl)) {
            return $this->answer($function, false, 'Access denied');
        }

        return $this->{lcfirst(str_replace('_', '', ucwords($function, '_')))}($function, $input, $actor, $request);
    }

    /**
     * A login link from create_user_session, followed in the browser: single use, and only before it expires.
     */
    public function login(string $path, Request $request): Response
    {
        $session = $this->state->get('sessions', $path);

        if (! is_array($session) || $session['used'] || $session['expires'] < time() || ! hash_equals((string) $session['session'], (string) ($request->query['session'] ?? ''))) {
            return new Response(403, ['Content-Type' => 'text/html'], '<!doctype html><title>Login link refused</title><p>This login link has expired or was already used.</p>');
        }

        $this->state->put('sessions', $path, [...$session, 'used' => true]);
        $where = $session['service'] === 'whostmgrd' ? 'WHM' : 'cPanel';

        return new Response(200, ['Content-Type' => 'text/html'], "<!doctype html><title>{$where}</title><p>Signed in to {$where} as {$session['user']}.</p>");
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function version(string $function, array $input, array $actor): Response
    {
        return $this->answer($function, true, data: ['version' => (string) $this->config('version')]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function myprivs(string $function, array $input, array $actor): Response
    {
        $privileges = $this->user($actor['user'])['privileges'] ?? [];

        return $this->answer($function, true, data: ['privileges' => [in_array('all', $privileges, true) ? ['all' => 1] : [...array_fill_keys($privileges, 1), 'all' => 0]]]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function apiTokenGetDetails(string $function, array $input, array $actor): Response
    {
        $token = $this->state->get('tokens', hash('sha256', $input['token'] ?? ''));

        if (! is_array($token) || $token['user'] !== $actor['user']) {
            return $this->answer($function, false, 'No API token with that value exists for this user.');
        }

        return $this->answer($function, true, data: array_intersect_key($token, array_flip(['name', 'create_time', 'expires_at', 'whitelist_ips', 'acls'])));
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function acctcounts(string $function, array $input, array $actor): Response
    {
        $user = $input['user'] ?? $actor['user'];
        $reseller = $this->user($user);

        if ($actor['user'] !== 'root' && $user !== $actor['user']) {
            return $this->answer($function, false, 'Access denied');
        }

        if ($reseller === null || ! ($reseller['reseller'] ?? false)) {
            return $this->answer($function, false, "“{$user}” is not a reseller.");
        }

        $owned = array_filter($this->state->all('accounts'), fn (array $account): bool => $account['owner'] === $user);
        $suspended = count(array_filter($owned, fn (array $account): bool => $account['suspended'] === 1));

        return $this->answer($function, true, data: ['reseller' => ['user' => $user, 'active' => count($owned) - $suspended, 'suspended' => $suspended, 'limit' => (int) $reseller['limit']]]);
    }

    /**
     * Search (`searchtype`, `searchmethod` exact or regex, `search`), field selection (`want`) and paging
     * (`api.chunk.enable`, `api.chunk.size`, `api.chunk.start`, counted from 1).
     *
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function listaccts(string $function, array $input, array $actor): Response
    {
        $field = $input['searchtype'] ?? '';
        $search = $input['search'] ?? '';
        $accounts = array_values(array_filter($this->visibleAccounts($actor), function (array $account) use ($field, $search, $input): bool {
            if ($search === '' || ! in_array($field, ['user', 'domain', 'owner', 'ip'], true)) {
                return true;
            }

            return ($input['searchmethod'] ?? 'regex') === 'exact'
                ? strcasecmp((string) $account[$field], $search) === 0
                : stripos((string) $account[$field], $search) !== false;
        }));

        if (($input['api.chunk.enable'] ?? '0') === '1') {
            $accounts = array_slice($accounts, max(0, (int) ($input['api.chunk.start'] ?? 1) - 1), max(1, (int) ($input['api.chunk.size'] ?? 100)));
        }

        $want = array_filter(explode(',', $input['want'] ?? ''));

        return $this->answer($function, true, data: ['acct' => $want === [] ? $accounts : array_map(fn (array $account): array => array_intersect_key($account, array_flip($want)), $accounts)]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function accountsummary(string $function, array $input, array $actor): Response
    {
        $account = $this->visibleAccount($actor, $input['user'] ?? '');

        return $account === null
            ? $this->answer($function, false, 'Account “'.($input['user'] ?? '').'” does not exist.')
            : $this->answer($function, true, data: ['acct' => [$account]]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function listpkgs(string $function, array $input, array $actor): Response
    {
        return $this->answer($function, true, data: ['pkg' => array_values(array_map(
            fn (array $package): array => array_intersect_key($package, array_flip(['name', 'QUOTA', 'BWLIMIT', 'MAXADDON'])),
            $this->usablePackages($actor),
        ))]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function showbw(string $function, array $input, array $actor): Response
    {
        $rows = array_map(fn (array $account): array => [
            'user' => $account['user'],
            'maindomain' => $account['domain'],
            'totalbytes' => (int) $this->state->get('bandwidth', $account['user'], 0),
            'limit' => $this->bytes($this->state->get('packages', $account['plan'])['BWLIMIT'] ?? 'unlimited'),
            'bwusage' => [],
        ], $this->visibleAccounts($actor));

        return $this->answer($function, true, data: [
            'month' => (int) ($input['month'] ?? date('n')),
            'year' => (int) ($input['year'] ?? date('Y')),
            'reseller' => $actor['user'],
            'totalused' => array_sum(array_column($rows, 'totalbytes')),
            'acct' => $rows,
        ]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function getNameserverConfig(string $function, array $input, array $actor): Response
    {
        return $this->answer($function, true, data: ['nameservers' => $this->config('nameservers')]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function verifyNewUsername(string $function, array $input, array $actor): Response
    {
        $username = $input['user'] ?? '';
        $refusal = UsernameRules::violation($username) ?? ($this->state->get('accounts', $username) !== null ? "The username “{$username}” is not available: an account already uses that name." : null);

        return $this->answer($function, $refusal === null, (string) $refusal);
    }

    /**
     * Every check first, then the account, its zone and its IP in one transaction, so two workers creating the same
     * name or domain cannot both succeed.
     *
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function createacct(string $function, array $input, array $actor): Response
    {
        return $this->state->transaction(function () use ($function, $input, $actor): Response {
            $username = $input['username'] ?? '';
            $domain = strtolower(trim($input['domain'] ?? ''));
            $domain = $domain !== '' ? $domain : "{$username}.whm-temp.".$this->config('hostname');
            $plan = $input['plan'] ?? 'default';
            $package = $this->state->get('packages', $plan);
            $owner = $this->user($actor['user']);
            $owned = count(array_filter($this->state->all('accounts'), fn (array $account): bool => $account['owner'] === $actor['user']));
            $strength = UsernameRules::strength($input['password'] ?? '');

            $refusal = match (true) {
                ! $this->config('licence_valid') => 'The cPanel license for this server is not valid; account creation is disabled.',
                UsernameRules::violation($username) !== null => UsernameRules::violation($username),
                $this->state->get('accounts', $username) !== null => "Sorry, the username “{$username}” is already taken by another account.",
                preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1 => "The domain “{$domain}” is not a valid domain name.",
                $this->state->get('zones', $domain) !== null => "Sorry, the domain {$domain} already exists on this server.",
                $package === null => "The package “{$plan}” does not exist.",
                ! array_key_exists($plan, $this->usablePackages($actor)) => "You are not allowed to use the package “{$plan}”.",
                $strength < 65 => "Sorry, the password you selected cannot be used because it is too weak and would be too easy to guess. Please choose a password with a strength rating of 65 or higher (this one rates {$strength}).",
                ($input['cpmod'] ?? '') !== '' && ! in_array($input['cpmod'], $this->config('themes'), true) => "The cpmod “{$input['cpmod']}” is not installed on this server.",
                ($owner['reseller'] ?? false) && $owned >= (int) $owner['limit'] => "The reseller {$actor['user']} has reached its limit of {$owner['limit']} accounts.",
                default => null,
            };

            if ($refusal !== null) {
                return $this->answer($function, false, $refusal);
            }

            $ip = ($input['ip'] ?? 'n') === 'y' ? $this->takeDedicatedIp() : (string) $this->config('shared_ip');

            if ($ip === null) {
                return $this->answer($function, false, 'No dedicated IP address is free on this server.');
            }

            $this->state->put('accounts', $username, [
                'user' => $username,
                'domain' => $domain,
                'plan' => $plan,
                'owner' => $actor['user'],
                'email' => ($input['contactemail'] ?? '') !== '' ? $input['contactemail'] : '*unknown*',
                'ip' => $ip,
                'suspended' => 0,
                'is_locked' => 0,
                'suspendreason' => 'not suspended',
                'unix_startdate' => time(),
                'diskused' => '0M',
                'disklimit' => $this->diskLimit($package),
            ]);
            $this->state->put('zones', $domain, ['user' => $username]);
            $this->state->put('passwords', $username, password_hash($input['password'] ?? '', PASSWORD_DEFAULT));

            $nameservers = (array) $this->config('nameservers');

            return $this->answer($function, true, data: ['ip' => $ip, 'nameserver' => (string) ($nameservers[0] ?? ''), 'nameserver2' => (string) ($nameservers[1] ?? ''), 'package' => $plan], output: "Account creation started.\nCreating the home directory, mail, databases and the DNS zone for {$domain}... done.");
        });
    }

    /**
     * Root suspending with `disallowun=1` locks the suspension: the owning reseller cannot lift it.
     *
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function suspendacct(string $function, array $input, array $actor): Response
    {
        $account = $this->visibleAccount($actor, $input['user'] ?? '');

        if ($account === null) {
            return $this->answer($function, false, 'Account “'.($input['user'] ?? '').'” does not exist.');
        }

        if ($account['suspended'] === 1) {
            return $this->answer($function, false, "The account “{$account['user']}” is already suspended.");
        }

        $this->state->put('accounts', $account['user'], [...$account,
            'suspended' => 1,
            'is_locked' => $actor['user'] === 'root' && ($input['disallowun'] ?? '0') === '1' ? 1 : 0,
            'suspendreason' => ($input['reason'] ?? '') !== '' ? $input['reason'] : 'Suspended',
        ]);

        return $this->answer($function, true);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function unsuspendacct(string $function, array $input, array $actor): Response
    {
        $account = $this->visibleAccount($actor, $input['user'] ?? '');

        if ($account === null) {
            return $this->answer($function, false, 'Account “'.($input['user'] ?? '').'” does not exist.');
        }

        if ($account['is_locked'] === 1 && $actor['user'] !== 'root') {
            return $this->answer($function, false, 'The administrator locked this suspension; it cannot be lifted from this login.');
        }

        $this->state->put('accounts', $account['user'], [...$account, 'suspended' => 0, 'is_locked' => 0, 'suspendreason' => 'not suspended']);

        return $this->answer($function, true);
    }

    /**
     * `keepdns=1` leaves the zone behind, which blocks a new account for the domain until it is removed.
     *
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function removeacct(string $function, array $input, array $actor): Response
    {
        $username = $input['username'] ?? $input['user'] ?? '';
        $account = $this->visibleAccount($actor, $username);

        if ($account === null) {
            return $this->answer($function, false, "The user “{$username}” does not exist.");
        }

        $this->state->transaction(function () use ($account, $input): void {
            $this->state->delete('accounts', $account['user']);
            $this->state->delete('passwords', $account['user']);
            $this->state->delete('bandwidth', $account['user']);

            if (($input['keepdns'] ?? '0') !== '1') {
                $this->state->delete('zones', $account['domain']);
            }

            if (in_array($account['ip'], $this->config('dedicated_ips'), true)) {
                $this->state->put('config', 'free_ips', [...$this->config('free_ips'), $account['ip']]);
            }
        });

        return $this->answer($function, true, output: "Removing the account files, mail and databases of {$account['user']}... done.");
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function changepackage(string $function, array $input, array $actor): Response
    {
        $account = $this->visibleAccount($actor, $input['user'] ?? '');
        $plan = $input['pkg'] ?? '';
        $package = $this->state->get('packages', $plan);

        $refusal = match (true) {
            $account === null => 'Account “'.($input['user'] ?? '').'” does not exist.',
            $package === null => "The package “{$plan}” does not exist.",
            ! array_key_exists($plan, $this->usablePackages($actor)) => "You are not allowed to use the package “{$plan}”.",
            default => null,
        };

        if ($refusal !== null || $account === null || $package === null) {
            return $this->answer($function, false, (string) $refusal);
        }

        $this->state->put('accounts', $account['user'], [...$account, 'plan' => $plan, 'disklimit' => $this->diskLimit($package)]);

        return $this->answer($function, true);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function passwd(string $function, array $input, array $actor): Response
    {
        $account = $this->visibleAccount($actor, $input['user'] ?? '');
        $strength = UsernameRules::strength($input['password'] ?? '');

        if ($account === null) {
            return $this->answer($function, false, 'Account “'.($input['user'] ?? '').'” does not exist.');
        }

        if ($strength < 65) {
            return $this->answer($function, false, "The password you selected cannot be used: its strength rating is {$strength}, below the required 65.");
        }

        $this->state->put('passwords', $account['user'], password_hash($input['password'] ?? '', PASSWORD_DEFAULT));

        return $this->answer($function, true, data: ['app' => 'system']);
    }

    /**
     * A single-use login link on the address the client connected to: `cpaneld` for an account the actor can see,
     * `whostmgrd` for the actor itself.
     *
     * @param  array<string, string>  $input
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function createUserSession(string $function, array $input, array $actor, Request $request): Response
    {
        $user = $input['user'] ?? '';
        $service = $input['service'] ?? 'cpaneld';
        $allowed = $service === 'whostmgrd' ? $user === $actor['user'] : $this->visibleAccount($actor, $user) !== null;

        if (! in_array($service, ['cpaneld', 'whostmgrd'], true) || ! $allowed) {
            return $this->answer($function, false, "You cannot create a session for “{$user}” on {$service}.");
        }

        $path = 'cpsess'.random_int(1_000_000_000, 9_999_999_999);
        $session = $user.':'.bin2hex(random_bytes(8)).':CREATE_USER_SESSION,'.bin2hex(random_bytes(16));
        $expires = time() + self::SESSION_SECONDS;
        $this->state->put('sessions', $path, ['user' => $user, 'service' => $service, 'session' => $session, 'expires' => $expires, 'used' => false]);
        $host = $request->header('host') ?? '127.0.0.1:'.$this->scenario->apiPort();

        return $this->answer($function, true, data: [
            'session' => $session,
            'cp_security_token' => "/{$path}",
            'expires' => $expires,
            'service' => $service,
            'url' => "https://{$host}/{$path}/login/?session=".rawurlencode($session),
        ]);
    }

    /**
     * The signed-in user with the ACLs this request may use, or a reason (or null) for a 401.
     *
     * @return array{user: string, acls: list<string>}|string|null
     */
    private function authenticate(Request $request): array|string|null
    {
        $header = (string) $request->header('authorization');

        if (preg_match('/^whm ([a-z0-9]+):(\S+)$/i', $header, $match) === 1) {
            $token = $this->state->get('tokens', hash('sha256', $match[2]));
            $valid = is_array($token) && $token['user'] === $match[1] && ($token['expires_at'] === null || $token['expires_at'] > time());

            return $valid ? ['user' => $match[1], 'acls' => $token['acls']] : null;
        }

        if (preg_match('/^Basic (\S+)$/i', $header, $match) === 1) {
            [$name, $password] = array_pad(explode(':', (string) base64_decode($match[1], true), 2), 2, '');
            $user = $this->user($name);

            if ($user === null || ! password_verify($password, (string) $user['password_hash'])) {
                return null;
            }

            return ($user['two_factor'] ?? false) ? 'Access denied: this login requires two-factor authentication.' : ['user' => $name, 'acls' => ['all']];
        }

        return null;
    }

    /**
     * A user's own privileges and the token's ACLs must both grant it.
     *
     * @param  array{user: string, acls: list<string>}  $actor
     */
    private function allowed(array $actor, string $acl): bool
    {
        $privileges = $this->user($actor['user'])['privileges'] ?? [];

        return (in_array('all', $privileges, true) || in_array($acl, $privileges, true))
            && (in_array('all', $actor['acls'], true) || in_array($acl, $actor['acls'], true));
    }

    /**
     * Root sees every account; a reseller only those it owns.
     *
     * @param  array{user: string, acls: list<string>}  $actor
     * @return list<array<string, mixed>>
     */
    private function visibleAccounts(array $actor): array
    {
        return array_values(array_filter($this->state->all('accounts'), fn (array $account): bool => $actor['user'] === 'root' || $account['owner'] === $actor['user']));
    }

    /**
     * @param  array{user: string, acls: list<string>}  $actor
     * @return array<string, mixed>|null
     */
    private function visibleAccount(array $actor, string $username): ?array
    {
        $account = $this->state->get('accounts', $username);

        return is_array($account) && ($actor['user'] === 'root' || $account['owner'] === $actor['user']) ? $account : null;
    }

    /**
     * Root may assign every package; a reseller its own and those root granted it.
     *
     * @param  array{user: string, acls: list<string>}  $actor
     * @return array<string, array<string, mixed>>
     */
    private function usablePackages(array $actor): array
    {
        $grants = $this->user($actor['user'])['packages'] ?? [];

        return array_filter($this->state->all('packages'), fn (array $package): bool => $actor['user'] === 'root' || $package['owner'] === $actor['user'] || in_array($package['name'], $grants, true));
    }

    private function takeDedicatedIp(): ?string
    {
        $free = $this->config('free_ips');
        $ip = array_shift($free);
        $this->state->put('config', 'free_ips', $free);

        return $ip;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(string $name): ?array
    {
        $user = $this->state->get('users', $name);

        return is_array($user) ? $user : null;
    }

    private function config(string $key): mixed
    {
        return $this->state->get('config', $key);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function diskLimit(array $package): string
    {
        return $package['QUOTA'] === 'unlimited' ? 'unlimited' : $package['QUOTA'].'M';
    }

    /**
     * A package limit in MiB ("unlimited" or a number) as bytes, 0 for unlimited.
     */
    private function bytes(mixed $megabytes): int
    {
        return is_numeric($megabytes) ? (int) $megabytes * 1_048_576 : 0;
    }

    /**
     * The WHM API 1 envelope.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function answer(string $function, bool $ok, string $reason = '', ?array $data = null, ?string $output = null): Response
    {
        $metadata = ['result' => $ok ? 1 : 0, 'reason' => $ok ? 'OK' : $reason, 'command' => $function, 'version' => 1];

        if ($output !== null) {
            $metadata['output'] = ['raw' => $output];
        }

        return Response::json($data === null ? ['metadata' => $metadata] : ['metadata' => $metadata, 'data' => $data]);
    }
}
