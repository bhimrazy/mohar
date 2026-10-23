<?php

namespace Mohar\Simulators\Providers\Whmcs;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * The WHMCS external API for the migration connector (phase 2a: clients), written from the public API reference in our
 * own words:
 *
 * - one URL (`/includes/api.php`), the action named by `action=`, `identifier` and `secret` on every call (or the
 *   `username` and `password` fields they may stand in), an optional `accesskey` that replaces the IP allow-list;
 * - `result=success|error` with `message` on errors, JSON only;
 * - `WhmcsDetails`, `GetClients` (`limitstart`, `limitnum`, `status`, `search`, `orderby`, `sorting`; `totalresults`,
 *   `startnumber`, `numreturned`) and `GetClientsDetails` (`clientid` or `email`, `stats`).
 *
 * Wording of errors and formats the reference does not state are assumptions listed in the README. Credentials are
 * for local scenarios only.
 */
final class WhmcsSimulator implements HasControlActions, Simulator
{
    public const IDENTIFIER = 'sim-whmcs-identifier';

    public const SECRET = 'sim-whmcs-secret-LocalScenario0000';

    public const VERSION = '8.13.1';

    /** Not the app's default zone, and one with daylight saving, so a missing conversion shows. */
    public const DEFAULT_TIMEZONE = 'America/New_York';

    public const PAGE_SIZE = 25;

    private const STATUSES = ['active', 'inactive', 'closed'];

    private const ORDER_COLUMNS = ['id', 'firstname', 'lastname', 'companyname', 'email', 'groupid', 'datecreated', 'status'];

    public function key(): string
    {
        return 'whmcs';
    }

    public function reference(): array
    {
        return ['url' => 'https://developers.whmcs.com/api/', 'version' => 'External API (WhmcsDetails, GetClients, GetClientsDetails)'];
    }

    public function routes(): array
    {
        $action = fn (string $name, string $operation, \Closure $handler): Route => new Route(
            '*',
            '/includes/api.php',
            $operation,
            fn (Request $request, Scenario $scenario): Response => $this->refused($request, $scenario, $name) ?? $handler($request, new WhmcsAccount($scenario)),
            fn (Request $request): bool => strcasecmp((string) ($request->input()['action'] ?? ''), $name) === 0,
        );

        return [
            $action('WhmcsDetails', 'whmcs_details', fn (Request $request, WhmcsAccount $account): Response => self::ok($account, [
                'whmcs' => ['version' => self::VERSION, 'canonicalversion' => self::VERSION.'-release.1'],
            ])),
            $action('GetClients', 'get_clients', $this->getClients(...)),
            $action('GetClientsDetails', 'get_clients_details', $this->getClientsDetails(...)),
            new Route('*', '/includes/api.php', 'unknown_action', function (Request $request, Scenario $scenario): Response {
                $name = (string) ($request->input()['action'] ?? '');

                return $this->refused($request, $scenario, $name) ?? self::error($name === '' ? 'No action was given.' : 'The action is not supported.');
            }),
        ];
    }

    /**
     * The checks every call passes first: the IP allow-list or access key, the credentials, the API role.
     */
    private function refused(Request $request, Scenario $scenario, string $action): ?Response
    {
        $account = new WhmcsAccount($scenario);
        $input = $request->input();
        $key = $account->setting('access_key');

        if ($account->setting('ip_restricted') === true && ! (is_string($key) && $key !== '' && hash_equals($key, (string) ($input['accesskey'] ?? '')))) {
            return self::error('Invalid IP '.$request->remoteAddress);
        }

        $identifier = (string) ($input['identifier'] ?? $input['username'] ?? '');
        $secret = (string) ($input['secret'] ?? $input['password'] ?? '');

        if (! hash_equals(self::IDENTIFIER, $identifier) | ! hash_equals(self::SECRET, $secret)) {
            return self::error('Authentication Failed');
        }

        if (in_array(strtolower($action), array_map('strtolower', (array) $account->setting('denied_actions')), true)) {
            return self::error('Invalid Permissions: API action "'.$action.'" is not allowed');
        }

        return null;
    }

    private function getClients(Request $request, WhmcsAccount $account): Response
    {
        $input = $request->input();
        $account->applySchedule((int) $account->scenario()->state()->get('calls', 'get_clients', 0));

        $clients = $account->all();

        if (isset($input['status']) && $input['status'] !== '') {
            $status = strtolower((string) $input['status']);
            $clients = array_filter($clients, fn (array $client): bool => in_array($status, self::STATUSES, true) && strtolower($client['status']) === $status);
        }

        if (isset($input['search']) && $input['search'] !== '') {
            $needle = strtolower((string) $input['search']);
            $clients = array_filter($clients, fn (array $client): bool => array_any(
                [$client['email'], $client['firstname'], $client['lastname'], $client['firstname'].' '.$client['lastname'], $client['companyname']],
                fn (string $value): bool => str_starts_with(strtolower($value), $needle),
            ));
        }

        $column = strtolower((string) ($input['orderby'] ?? ''));
        $column = in_array($column, self::ORDER_COLUMNS, true) ? $column : 'id';
        $field = $column === 'datecreated' ? 'created_at' : $column;
        $direction = strtoupper((string) ($input['sorting'] ?? 'ASC')) === 'DESC' ? -1 : 1;

        usort($clients, fn (array $a, array $b): int => $direction * (is_int($a[$field]) ? $a[$field] <=> $b[$field] : strcasecmp((string) $a[$field], (string) $b[$field])) ?: $a['id'] <=> $b['id']);

        $start = max(0, (int) ($input['limitstart'] ?? 0));
        $limit = (int) ($input['limitnum'] ?? 0) > 0 ? (int) $input['limitnum'] : self::PAGE_SIZE;
        $page = array_map(fn (array $client): array => [
            'id' => $client['id'], 'firstname' => $client['firstname'], 'lastname' => $client['lastname'], 'companyname' => $client['companyname'],
            'email' => $client['email'], 'datecreated' => $account->date($client['created_at']), 'groupid' => $client['groupid'], 'status' => $client['status'],
        ], array_slice($clients, $start, $limit));

        return self::ok($account, [
            'totalresults' => count($clients),
            'startnumber' => $start,
            'numreturned' => count($page),
            'clients' => ['client' => $account->setting('single_item_as_object') === true && count($page) === 1 ? $page[0] : $page],
        ]);
    }

    private function getClientsDetails(Request $request, WhmcsAccount $account): Response
    {
        $input = $request->input();
        $client = null;

        if (isset($input['clientid']) && $input['clientid'] !== '') {
            $client = $account->find((int) $input['clientid']);
        } elseif (isset($input['email']) && $input['email'] !== '') {
            $client = array_find($account->all(), fn (array $row): bool => strcasecmp($row['email'], (string) $input['email']) === 0);
        } else {
            return self::error('Either clientid or email is required');
        }

        if ($client === null) {
            return self::error('Client Not Found');
        }

        $details = [
            'id' => $client['id'], 'userid' => $client['id'], 'owner_user_id' => $client['id'], 'uuid' => $client['uuid'],
            'firstname' => $client['firstname'], 'lastname' => $client['lastname'], 'fullname' => $client['firstname'].' '.$client['lastname'],
            'companyname' => $client['companyname'], 'email' => $client['email'],
            'phonecc' => $client['phonecc'], 'phonenumber' => $client['phonenumber'], 'phonenumberformatted' => '+'.$client['phonecc'].'.'.$client['phonenumber'],
            'address1' => $client['address1'], 'address2' => $client['address2'], 'city' => $client['city'], 'state' => $client['state'], 'fullstate' => $client['state'],
            'postcode' => $client['postcode'], 'countrycode' => $client['countrycode'], 'country' => $client['country'],
            'tax_id' => $client['tax_id'], 'notes' => $client['notes'], 'currency' => $client['currency'], 'currency_code' => $client['currency_code'],
            'defaultgateway' => $client['defaultgateway'], 'groupid' => $client['groupid'], 'status' => $client['status'], 'credit' => $client['credit'],
            'language' => $client['language'], 'lastlogin' => $account->datetime($client['lastlogin_at']),
            'taxexempt' => false, 'separateinvoices' => false, 'disableautocc' => false, 'emailoptout' => false, 'marketing_emails_opt_in' => true, 'email_verified' => true,
            'email_preferences' => ['general' => '1', 'invoice' => '1', 'support' => '1', 'product' => '1', 'domain' => '1', 'affiliate' => '1'],
            'customfields' => [],
            'users' => [['id' => $client['id'], 'name' => $client['firstname'].' '.$client['lastname'], 'email' => $client['email'], 'is_owner' => true]],
        ];
        $answer = ['client' => $details, ...$details];

        if (filter_var($input['stats'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $answer['stats'] = ['numdueinvoices' => 0, 'numoverdueinvoices' => 0, 'numpaidinvoices' => 0, 'productsnumtotal' => 0, 'productsnumactive' => 0, 'numtickets' => 0];
        }

        return self::ok($account, $answer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function ok(WhmcsAccount $account, array $data): Response
    {
        $body = ['result' => 'success', ...$data];

        return Response::json($account->setting('stringify_numbers') === true ? self::stringify($body) : $body);
    }

    private static function error(string $message): Response
    {
        return Response::json(['result' => 'error', 'message' => $message]);
    }

    private static function stringify(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::stringify(...), $value),
            is_int($value) => (string) $value,
            default => $value,
        };
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        (new WhmcsAccount($scenario))->seed();
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return [
            'env' => ['OUTBOUND_ALLOW_LOOPBACK' => 'true'],
            'notes' => [
                'WHMCS source: base URL '.$scenario->apiUrl().' (the API is at /includes/api.php), identifier '.self::IDENTIFIER.', secret '.self::SECRET.'.',
                'The scenario certificate is signed by the scenario CA; allow the loopback address and an insecure or trusted connection as for the other simulators.',
            ],
        ];
    }

    /**
     * - `seed_clients` {count, overrides?}: append generated clients (overrides: stored fields such as `firstname`, `status`, `created_at`);
     * - `update_client` {id, fields}, `remove_client` {id};
     * - `schedule_change` {at_call, add_count?, overrides?, remove_ids?}: the change happens as the Nth GetClients call arrives, before it is answered;
     * - `configure` {timezone, ip_restricted, access_key, denied_actions, single_item_as_object, stringify_numbers};
     * - `reset`: drop everything, the fault plan included, and reseed.
     */
    public function actions(): array
    {
        return [
            'seed_clients' => function (array $input, Scenario $scenario): array {
                $account = new WhmcsAccount($scenario);
                $ids = $account->addClients(max(0, (int) ($input['count'] ?? 1)), (array) ($input['overrides'] ?? []));

                return ['added' => count($ids), 'last_id' => $ids === [] ? null : end($ids), 'total' => count($account->all())];
            },
            'update_client' => function (array $input, Scenario $scenario): array {
                (new WhmcsAccount($scenario))->update((int) $input['id'], (array) ($input['fields'] ?? []));

                return ['id' => (int) $input['id']];
            },
            'remove_client' => function (array $input, Scenario $scenario): array {
                (new WhmcsAccount($scenario))->remove((int) $input['id']);

                return ['id' => (int) $input['id']];
            },
            'schedule_change' => function (array $input, Scenario $scenario): array {
                (new WhmcsAccount($scenario))->scheduleChange((int) $input['at_call'], (int) ($input['add_count'] ?? 0), (array) ($input['overrides'] ?? []), array_values(array_map('intval', (array) ($input['remove_ids'] ?? []))));

                return ['at_call' => (int) $input['at_call']];
            },
            'configure' => function (array $input, Scenario $scenario): array {
                (new WhmcsAccount($scenario))->configure($input);

                return ['configured' => array_keys($input)];
            },
            'reset' => function (array $input, Scenario $scenario): array {
                (new WhmcsAccount($scenario))->reset();

                return ['reset' => true];
            },
        ];
    }
}
