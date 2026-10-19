<?php

namespace App\Modules\Services\Support;

use App\Data\Catalog\ProductSummary;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Collection;

/**
 * Sorts the accounts a server reported into what Import accounts can do with them (cPanel spec §10). Read-only: it
 * proposes, staff decide, and nothing here creates a client or changes a service.
 *
 * - linked: a live service on this server already holds the username; it is never imported again.
 * - proposed: exactly one open client has the account's contact email, and exactly one product of the driver uses the
 *   account's package on this server (its group, or no group when the server is the driver's default).
 * - unmatched: anything else; the note says why.
 * - reseller: owned by another panel user than the connection's (a reseller under a root connection); listed apart.
 */
class AccountImportMatcher
{
    public const LINKED = 'linked';

    public const PROPOSED = 'proposed';

    public const UNMATCHED = 'unmatched';

    public const RESELLER = 'reseller';

    public function __construct(private Catalog $catalog, private ProvisioningDriverRegistry $drivers) {}

    /**
     * @param  list<array<string, mixed>>  $accounts  ServerAccountImport::$accounts
     * @return list<array<string, mixed>> each account with category, client, product, service, differences and note
     */
    public function classify(Server $server, array $accounts): array
    {
        $services = $this->liveServices($server)->keyBy(fn (Service $service): string => strtolower((string) $service->username));
        $clients = $this->clientsByEmail(array_values(array_unique(array_filter(array_map(fn (array $account): ?string => $account['email'] ?? null, $accounts)))));
        $field = $this->packageField($server->driver);
        $products = collect($this->catalog->priceBook()?->productsForDriver($server->driver) ?? []);
        $offered = $this->productsByPackage($server, $products, $field);

        return array_map(function (array $account) use ($server, $services, $clients, $products, $offered, $field): array {
            $username = strtolower((string) $account['username']);
            $service = $services->get($username);

            if ($service !== null) {
                return [...$account, 'category' => self::LINKED, 'client' => null, 'product' => null, 'note' => null,
                    'service' => ['id' => $service->id, 'label' => $service->label(), 'client' => $service->client->display_name],
                    'differences' => $this->differences($service, $account, $products->firstWhere('id', $service->product_id), $field)];
            }

            $email = $account['email'] ?? null;
            $matchedClients = $email === null ? [] : ($clients[$email] ?? []);
            $matchedProducts = $offered[(string) ($account['plan'] ?? '')] ?? [];
            $notes = array_filter([
                match (true) {
                    $email === null => self::text('The server reports no contact email.'),
                    $matchedClients === [] => self::text('No client has the email :email.', ['email' => $email]),
                    count($matchedClients) > 1 => self::text(':count clients share this email.', ['count' => count($matchedClients)]),
                    default => null,
                },
                match (true) {
                    $field === null => self::text('This driver names no package setting to match products by.'),
                    ($account['plan'] ?? null) === null => self::text('The server reports no package.'),
                    $matchedProducts === [] => self::text('No product uses the package :plan on this server.', ['plan' => $account['plan']]),
                    count($matchedProducts) > 1 => self::text(':count products use the package :plan.', ['count' => count($matchedProducts), 'plan' => $account['plan']]),
                    default => null,
                },
            ]);
            $client = count($matchedClients) === 1 ? $matchedClients[0] : null;
            $product = count($matchedProducts) === 1 ? $matchedProducts[0] : null;
            $owner = $account['owner'] ?? null;
            $resellerOwned = $owner !== null && $server->username !== null && $owner !== $server->username;

            return [...$account,
                'category' => match (true) {
                    $resellerOwned => self::RESELLER,
                    $client !== null && $product !== null => self::PROPOSED,
                    default => self::UNMATCHED,
                },
                'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->display_name],
                'product' => $product === null ? null : ['id' => $product->id, 'name' => $product->name],
                'note' => $notes === [] ? null : implode(' ', $notes),
                'service' => null,
                'differences' => [],
            ];
        }, $accounts);
    }

    /**
     * Live services on the server whose account the server did not report: flagged for staff, never changed.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return Collection<int, Service>
     */
    public function missing(Server $server, array $accounts): Collection
    {
        $reported = array_flip(array_map(fn (array $account): string => strtolower((string) $account['username']), $accounts));

        return $this->liveServices($server)
            ->reject(fn (Service $service): bool => isset($reported[strtolower((string) $service->username)]))
            ->values();
    }

    /**
     * @return Collection<int, Service>
     */
    private function liveServices(Server $server): Collection
    {
        return Service::query()
            ->with('client:id,first_name,last_name,company_name')
            ->where('server_id', $server->id)
            ->whereIn('status', ServiceStatus::occupyingValues())
            ->whereNotNull('username')
            ->orderBy('id')
            ->get(['id', 'client_id', 'product_id', 'product_name', 'status', 'domain', 'username']);
    }

    /**
     * Open clients by lowercase email (stored lowercase), with every client that shares one.
     *
     * @param  list<string>  $emails
     * @return array<string, list<Client>>
     */
    private function clientsByEmail(array $emails): array
    {
        $found = [];

        foreach (array_chunk($emails, 500) as $chunk) {
            Client::query()->whereIn('email', $chunk)->where('status', '!=', 'closed')->orderBy('id')
                ->get(['id', 'first_name', 'last_name', 'company_name', 'email'])
                ->each(function (Client $client) use (&$found): void {
                    $found[$client->email][] = $client;
                });
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
     * Products that provision on this server, by the package they use.
     *
     * @param  Collection<int, ProductSummary>  $products
     * @return array<string, list<ProductSummary>>
     */
    private function productsByPackage(Server $server, Collection $products, ?string $field): array
    {
        if ($field === null) {
            return [];
        }

        $groupIds = $server->groups()->pluck('server_groups.id')->map(fn (mixed $id): int => (int) $id)->all();
        $byPackage = [];

        foreach ($products as $product) {
            $package = $product->driverConfig[$field] ?? null;
            $here = $product->serverGroupId === null ? $server->is_default : in_array($product->serverGroupId, $groupIds, true);

            if ($here && is_string($package) && $package !== '') {
                $byPackage[$package][] = $product;
            }
        }

        return $byPackage;
    }

    /**
     * What the server reports differently from the linked service.
     *
     * @param  array<string, mixed>  $account
     * @return list<string>
     */
    private function differences(Service $service, array $account, ?ProductSummary $product, ?string $field): array
    {
        $package = $field === null ? null : ($product?->driverConfig[$field] ?? null);

        return array_values(array_filter([
            ($account['domain'] ?? null) !== null && strtolower((string) $service->domain) !== $account['domain']
                ? self::text('Domain on the server: :domain (here: :local).', ['domain' => $account['domain'], 'local' => $service->domain ?? self::text('none')]) : null,
            is_string($package) && ($account['plan'] ?? null) !== null && $account['plan'] !== $package
                ? self::text('Package on the server: :plan (the product uses :package).', ['plan' => $account['plan'], 'package' => $package]) : null,
            (bool) ($account['suspended'] ?? false) !== ($service->status === ServiceStatus::Suspended)
                ? (($account['suspended'] ?? false) ? self::text('Suspended on the server, not here.') : self::text('Suspended here, not on the server.')) : null,
        ]));
    }

    /**
     * A translated sentence (the translator may answer with an array for a group key; these keys are sentences).
     *
     * @param  array<string, mixed>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
