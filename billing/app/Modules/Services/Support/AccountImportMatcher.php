<?php

namespace App\Modules\Services\Support;

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Catalog\ProductSummary;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\ServerConnection;
use App\Enums\FieldType;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmClient;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sorts the accounts a server reported into what Import accounts can do with them (cPanel spec §10). It runs once,
 * when a fetch finishes (FetchServerAccounts), and its answer is stored with the list. Read-only: it proposes, staff
 * decide, and nothing here creates a client or changes a service.
 *
 * - linked: a live service on this server already holds the username; it is never imported again.
 * - reseller: owned by another panel user than the one the connection signs in as (a reseller's customer under a root
 *   connection, or the reseller's own account under a reseller connection); listed apart.
 * - proposed: exactly one open client has the account's contact email, that client is not flagged (clientFlags(): a
 *   recent email change, or a sign-up whose email no owner login verified), and exactly one product of the driver uses
 *   the account's package on this server (its group, or no group when the server is the driver's default).
 * - unmatched: anything else; the note says why.
 */
class AccountImportMatcher
{
    public const LINKED = 'linked';

    public const PROPOSED = 'proposed';

    public const UNMATCHED = 'unmatched';

    public const RESELLER = 'reseller';

    /** A client whose email changed this recently is never proposed or ticked in bulk. */
    public const EMAIL_CHANGE_DAYS = 30;

    public const FLAG_EMAIL_CHANGED = 'email_changed';

    public const FLAG_UNVERIFIED = 'unverified';

    public function __construct(private Catalog $catalog, private ProvisioningDriverRegistry $drivers, private AccountList $list, private ImportListHandover $handover) {}

    /**
     * Each account with category, client_id, product_id, service_id, note and differences, ready to store; and the ids
     * of live services on the server whose account was not reported.
     *
     * @param  list<array<string, mixed>>  $accounts  validated RemoteAccount fields
     * @param  string|null  $connectionUser  the panel user the connection signs in as (null: owners are not compared)
     * @param  CarbonImmutable|null  $listedAt  when the server listed the accounts; services created later are not missing
     * @return array{rows: list<array<string, mixed>>, missing: list<int>}
     */
    public function classify(Server $server, array $accounts, ?string $connectionUser, ?CarbonImmutable $listedAt = null): array
    {
        $context = $this->context($server);

        return ['rows' => $this->classifyRows($context, $accounts, $connectionUser), 'missing' => $this->missing($context, array_column($accounts, 'username'), $listedAt)];
    }

    /**
     * What classifying needs from the server, read once so a long list can be classified a chunk at a time.
     *
     * @param  list<string>|null  $usernames  only the live services holding these (lower-case) usernames
     * @return array{services: Collection<string, stdClass>, field: string|null, offered: array<string, list<int>>, packages: array<int, mixed>, server: Server}
     */
    public function context(Server $server, ?array $usernames = null): array
    {
        $field = $this->packageField($server->driver);

        return [
            'server' => $server,
            'services' => $this->liveServices($server, $usernames),
            'field' => $field,
            'offered' => $this->productsByPackage($server, $field),
            'packages' => $field === null ? [] : collect($this->catalog->priceBook()?->productsForDriver($server->driver) ?? [])
                ->mapWithKeys(fn (ProductSummary $product): array => [$product->id => $product->driverConfig[$field] ?? null])->all(),
        ];
    }

    /**
     * Ids of the live services whose username is not among $reported, except those created after the list was read.
     *
     * @param  array{services: Collection<string, stdClass>}  $context
     * @param  list<string>  $reported
     * @return list<int>
     */
    public function missing(array $context, array $reported, ?CarbonImmutable $listedAt): array
    {
        $reported = array_flip($reported);
        $missing = [];

        foreach ($context['services'] as $username => $service) {
            if (! isset($reported[$username]) && ($listedAt === null || CarbonImmutable::parse((string) $service->created_at)->lessThanOrEqualTo($listedAt))) {
                $missing[] = (int) $service->id;
            }
        }

        return $missing;
    }

    /**
     * @param  array{services: Collection<string, stdClass>, field: string|null, offered: array<string, list<int>>, packages: array<int, mixed>}  $context
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    public function classifyRows(array $context, array $accounts, ?string $connectionUser): array
    {
        ['services' => $services, 'field' => $field, 'offered' => $offered, 'packages' => $packages] = $context;
        $clients = $this->clientsByEmail(array_values(array_unique(array_filter(array_map(fn (array $account): ?string => $account['email'] ?? null, $accounts)))));
        $flags = $this->clientFlags(array_merge([], ...array_values(array_filter($clients, fn (array $ids): bool => count($ids) === 1))));

        return array_map(function (array $account) use ($services, $clients, $flags, $offered, $field, $packages, $connectionUser): array {
            $service = $services->get($account['username']);

            if ($service !== null) {
                return [...$account, 'category' => self::LINKED, 'client_id' => null, 'product_id' => null, 'service_id' => (int) $service->id, 'note' => null,
                    'differences' => $this->differences($service, $account, $packages[$service->product_id] ?? null)];
            }

            $email = $account['email'] ?? null;
            $plan = $account['plan'] ?? null;
            $matchedClients = $email === null ? [] : ($clients[$email] ?? []);
            $matchedProducts = $offered[(string) $plan] ?? [];
            $owner = $account['owner'] ?? null;
            // An owner WHM does not report (or reports invalidly) is unknown, so it is never taken as the connection's.
            // Fail closed: with no recorded connection user (a list read before it was stored) no owner is taken as ours.
            $otherOwner = $connectionUser === null || $owner !== $connectionUser;
            $notes = array_filter([
                $connectionUser === null ? self::text('This list does not record the WHM user it was read with: fetch it again to match owners.') : null,
                $otherOwner && $connectionUser !== null && $owner === null ? self::text('WHM reports no valid owner for this account, so it is listed apart.') : null,
                $otherOwner && $connectionUser !== null && $owner !== null ? ($connectionUser === 'root'
                    ? self::text('Owned by the reseller :owner.', ['owner' => $owner])
                    : self::text('Owned by :owner, not by :user, whose accounts this connection imports.', ['owner' => $owner, 'user' => $connectionUser])) : null,
                match (true) {
                    $email === null => self::text('The server reports no valid contact email.'),
                    $matchedClients === [] => self::text('No client has the email :email.', ['email' => $email]),
                    count($matchedClients) > 1 => self::text(':count clients share this email.', ['count' => count($matchedClients)]),
                    ! $otherOwner && isset($flags[$matchedClients[0]]) => self::text('Not proposed: choose this client yourself if the match is right.'),
                    default => null,
                },
                match (true) {
                    $field === null => self::text('This driver names no package setting to match products by.'),
                    $plan === null => self::text('The server reports no package.'),
                    $matchedProducts === [] => self::text('No product uses the package :plan on this server.', ['plan' => $plan]),
                    count($matchedProducts) > 1 => self::text(':count products use the package :plan.', ['count' => count($matchedProducts), 'plan' => $plan]),
                    default => null,
                },
            ]);
            // An account owned by another panel user is never proposed onto a client, even when its email matches.
            $clientId = ! $otherOwner && count($matchedClients) === 1 ? $matchedClients[0] : null;
            $productId = ! $otherOwner && count($matchedProducts) === 1 ? $matchedProducts[0] : null;

            return [...$account,
                'category' => match (true) {
                    $otherOwner => self::RESELLER,
                    // A flagged client is shown as the match but never proposed: staff choose it themselves.
                    $clientId !== null && $productId !== null && ! isset($flags[$clientId]) => self::PROPOSED,
                    default => self::UNMATCHED,
                },
                'client_id' => $clientId,
                'product_id' => $productId,
                'service_id' => null,
                'note' => $notes === [] ? null : implode(' ', $notes),
                'differences' => [],
            ];
        }, $accounts);
    }

    /**
     * Match the accounts and store them, with the counts, on a fetched import. Under the server lock, before the list
     * becomes ready: the list it replaces (a fetch; Match again replaces none) hands over its report and choices
     * (ImportListHandover). A fetch or match superseded while it ran (its row deleted or no longer running) stores
     * nothing and deletes nothing.
     *
     * @param  list<array<string, mixed>>  $accounts  validated RemoteAccount fields
     * @return int|null the number of accounts stored, null when superseded
     */
    public function store(ServerAccountImport $import, array $accounts, ?string $connectionUser): ?int
    {
        $matched = $this->classify($import->server, $accounts, $connectionUser, $import->fetched_at);

        $stored = DB::transaction(function () use ($import, $matched, $connectionUser): bool {
            Server::query()->whereKey($import->server_id)->lockForUpdate()->first();

            if (! in_array(ServerAccountImport::query()->whereKey($import->id)->value('status'), [ServerAccountImport::FETCHING, ServerAccountImport::MATCHING], true)) {
                return false;
            }

            $handover = $this->handover->take($import, $matched['rows']);
            $this->list->replace($import, $matched['rows']);
            $this->list->applyIgnores($import);
            $counts = $this->list->counts($import);
            $import->fill($handover)->update([
                'status' => ServerAccountImport::READY,
                'account_count' => array_sum($counts),
                'proposed_count' => $counts[self::PROPOSED] ?? 0,
                'unmatched_count' => $counts[self::UNMATCHED] ?? 0,
                'reseller_count' => $counts[self::RESELLER] ?? 0,
                'linked_count' => $counts[self::LINKED] ?? 0,
                'missing' => $matched['missing'],
                'fetched_at' => $import->fetched_at ?? now(),
                'connection_user' => $connectionUser,
            ]);

            return true;
        });

        return $stored ? count($matched['rows']) : null;
    }

    /**
     * Why an email match to each of these clients is not proposed nor ticked in bulk, by client id (clients without a
     * flag are left out). A match is trusted only when nobody vouches against it:
     *
     * - the client's email changed in the last EMAIL_CHANGE_DAYS days (whoever changed it); or
     * - the address is the client's own (EmailSource::Client: sign-up, checkout, the client-area account form) and no
     *   login on that client, owner or not, has verified that exact address. Staff and the importer vouch for the
     *   addresses they enter.
     *
     * @param  array<int>  $clientIds
     * @return array<int, array{kind: string, at: string|null}>
     */
    public function clientFlags(array $clientIds): array
    {
        $flags = [];
        $since = CarbonImmutable::now()->subDays(self::EMAIL_CHANGE_DAYS);

        foreach (array_chunk(array_values(array_unique($clientIds)), 500) as $chunk) {
            $logins = DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')
                ->whereIn('client_user.client_id', $chunk)->whereNotNull('users.email_verified_at')
                ->get(['client_user.client_id', 'users.email'])
                ->groupBy(fn (stdClass $login): int => (int) $login->client_id);

            foreach (Client::query()->toBase()->whereIn('id', $chunk)->get(['id', 'email', 'email_changed_at', 'email_source']) as $client) {
                $changed = $client->email_changed_at === null ? null : CarbonImmutable::parse((string) $client->email_changed_at);
                $vouched = EmailSource::tryFrom((string) $client->email_source)?->isVouched() ?? false;
                $verified = $logins->get((int) $client->id, collect())->contains(fn (stdClass $login): bool => mb_strtolower((string) $login->email) === mb_strtolower((string) $client->email));

                $flag = match (true) {
                    $changed !== null && $changed->greaterThanOrEqualTo($since) => ['kind' => self::FLAG_EMAIL_CHANGED, 'at' => $changed->toIso8601String()],
                    ! $vouched && ! $verified => ['kind' => self::FLAG_UNVERIFIED, 'at' => null],
                    default => null,
                };

                if ($flag !== null) {
                    $flags[(int) $client->id] = $flag;
                }
            }
        }

        return $flags;
    }

    /**
     * "Match again": the stored accounts matched against today's clients, products and services, with the owner
     * compared to the user the fetch signed in as. Nothing is sent to the server. The list is read and rewritten a
     * chunk at a time, and only accounts whose answer changed are written, so memory does not grow with the list.
     * A match superseded while it ran (no longer MATCHING) writes nothing more.
     */
    public function rematch(ServerAccountImport $import): void
    {
        $context = $this->context($import->server);
        $keys = array_flip(['category', 'client_id', 'product_id', 'service_id', 'note', 'differences']);
        $reported = [];

        foreach ($this->list->chunks($import) as $chunk) {
            $accounts = array_map(fn (array $record): array => array_diff_key($record['payload'], $keys), $chunk);
            // A list read before the connection user was recorded: owners cannot be compared, so nothing is proposed
            // (classifyRows() treats a null connection user as unknown).
            $fresh = $this->classifyRows($context, $accounts, $import->connection_user);
            $rows = [];
            $categories = [];

            foreach ($chunk as $index => $record) {
                $username = $record['username'];
                $reported[] = $username;
                // An ignored account stays ignored unless a service holds it now.
                $category = $record['category'] === AccountList::IGNORED && $fresh[$index]['category'] !== self::LINKED ? AccountList::IGNORED : $fresh[$index]['category'];

                if ($fresh[$index] != $record['payload'] || $category !== $record['category']) {
                    $rows[$username] = $fresh[$index];
                    $categories[$username] = $category;
                }
            }

            if (! DB::transaction(function () use ($import, $rows, $categories): bool {
                if (ServerAccountImport::query()->whereKey($import->id)->lockForUpdate()->value('status') !== ServerAccountImport::MATCHING) {
                    return false;
                }

                $this->list->rewrite($import, $rows, $categories);

                return true;
            })) {
                return;
            }
        }

        DB::transaction(function () use ($import, $context, $reported): void {
            Server::query()->whereKey($import->server_id)->lockForUpdate()->first();

            if (ServerAccountImport::query()->whereKey($import->id)->value('status') !== ServerAccountImport::MATCHING) {
                return;
            }

            $counts = $this->list->counts($import);
            $import->update([
                'status' => ServerAccountImport::READY, 'account_count' => array_sum($counts),
                'proposed_count' => $counts[self::PROPOSED] ?? 0, 'unmatched_count' => $counts[self::UNMATCHED] ?? 0,
                'reseller_count' => $counts[self::RESELLER] ?? 0, 'linked_count' => $counts[self::LINKED] ?? 0,
                'missing' => $this->missing($context, $reported, $import->fetched_at),
            ]);
            ServerAccountImport::query()->where('server_id', $import->server_id)->where('id', '<', $import->id)->delete();
        });
    }

    /**
     * The panel user the connection signs in as, exactly as the driver decides it: a cPanel server without a username
     * signs in as root.
     */
    public static function connectionUser(ProvisioningDriver $driver, ServerConnection $connection): ?string
    {
        return $driver instanceof CpanelDriver ? WhmClient::connectionUser($connection) : $connection->username;
    }

    /**
     * The driver's products that provision on this server: its groups' products, plus products without a group when
     * the server is the driver's default.
     *
     * @return array<int, ProductSummary> by id
     */
    public function serverProducts(Server $server): array
    {
        $groupIds = $server->groups()->pluck('server_groups.id')->map(fn (mixed $id): int => (int) $id)->all();

        return collect($this->catalog->priceBook()?->productsForDriver($server->driver) ?? [])
            ->filter(fn (ProductSummary $product): bool => $product->serverGroupId === null ? $server->is_default : in_array($product->serverGroupId, $groupIds, true))
            ->keyBy('id')
            ->all();
    }

    /**
     * A translated sentence (the translator may answer with an array for a group key; these keys are sentences).
     *
     * @param  array<string, mixed>  $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * Live services on the server by username: one query, plain rows, no models.
     *
     * @return Collection<string, stdClass>
     */
    private function liveServices(Server $server, ?array $usernames = null): Collection
    {
        return DB::table('services')
            ->where('server_id', $server->id)
            ->when($usernames !== null, fn ($query) => $query->whereIn(DB::raw('lower(username)'), $usernames))
            ->whereIn('status', ServiceStatus::occupyingValues())
            ->whereNull('deleted_at')
            ->whereNotNull('username')
            ->orderBy('id')
            ->get(['id', 'product_id', 'status', 'domain', 'username', 'driver_data', 'created_at'])
            ->keyBy(fn (stdClass $service): string => strtolower((string) $service->username));
    }

    /**
     * Open clients' ids by lowercase email (stored lowercase), with every client that shares one.
     *
     * @param  list<string>  $emails
     * @return array<string, list<int>>
     */
    private function clientsByEmail(array $emails): array
    {
        $found = [];

        foreach (array_chunk($emails, 500) as $chunk) {
            foreach (Client::query()->toBase()->whereIn('email', $chunk)->where('status', '!=', 'closed')->orderBy('id')->get(['id', 'email']) as $client) {
                $found[(string) $client->email][] = (int) $client->id;
            }
        }

        return $found;
    }

    /**
     * The driver's product setting that names the panel package: its first select filled from the server.
     */
    private function packageField(string $driverKey): ?string
    {
        if (! $this->drivers->has($driverKey)) {
            return null;
        }

        $field = collect($this->drivers->metadata($driverKey)->productConfigFields)
            ->first(fn (SettingField $field): bool => $field->type === FieldType::Select && $field->optionsSource === SettingField::OPTIONS_REMOTE);

        return $field?->key;
    }

    /**
     * Ids of the products that provision on this server, by the package they use.
     *
     * @return array<string, list<int>>
     */
    private function productsByPackage(Server $server, ?string $field): array
    {
        $byPackage = [];

        foreach ($field === null ? [] : $this->serverProducts($server) as $product) {
            $package = $product->driverConfig[$field] ?? null;

            if (is_string($package) && $package !== '') {
                $byPackage[$package][] = $product->id;
            }
        }

        return $byPackage;
    }

    /**
     * What the server reports differently from the linked service: domain, package, suspension and IP (§10).
     *
     * @param  array<string, mixed>  $account
     * @return list<string>
     */
    private function differences(stdClass $service, array $account, mixed $package): array
    {
        $ip = json_decode((string) $service->driver_data, true)['ip'] ?? null;
        $suspended = (bool) ($account['suspended'] ?? false);

        return array_values(array_filter([
            ($account['domain'] ?? null) !== null && strtolower((string) $service->domain) !== $account['domain']
                ? self::text('Domain on the server: :domain (here: :local).', ['domain' => $account['domain'], 'local' => $service->domain ?? self::text('none')]) : null,
            is_string($package) && ($account['plan'] ?? null) !== null && $account['plan'] !== $package
                ? self::text('Package on the server: :plan (the product uses :package).', ['plan' => $account['plan'], 'package' => $package]) : null,
            $suspended !== ($service->status === ServiceStatus::Suspended->value)
                ? ($suspended ? self::text('Suspended on the server, not here.') : self::text('Suspended here, not on the server.')) : null,
            is_string($ip) && ($account['ip'] ?? null) !== null && $account['ip'] !== $ip
                ? self::text('IP on the server: :ip (here: :local).', ['ip' => $account['ip'], 'local' => $ip]) : null,
        ]));
    }
}
