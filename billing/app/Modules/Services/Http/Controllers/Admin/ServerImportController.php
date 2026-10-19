<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountImportPlan;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Servers → server → Import accounts (cPanel spec §10): fetch what the server holds and show how each account
 * matched. The match is stored when the fetch finishes; this page only filters and pages it, and re-checks the rows it
 * shows (a service created since, a client closed since). Read-only on both sides; importing is a separate step.
 */
class ServerImportController extends Controller
{
    public const TABS = ['all', AccountImportMatcher::PROPOSED, AccountImportMatcher::UNMATCHED, AccountImportMatcher::RESELLER, AccountImportMatcher::LINKED, 'missing'];

    private const PER_PAGE = 50;

    public function show(Request $request, Server $server, ProvisioningDriverRegistry $drivers, AccountImportMatcher $matcher, AccountImportPlan $plan): Response
    {
        $this->authorize('services.servers.import');

        $tab = in_array($tab = $request->string('tab')->toString(), self::TABS, true) ? $tab : 'all';
        // The encrypted list is read only for the account tabs; the counts and missing ids are plain columns.
        $import = FetchAccountsForImport::latest($server, withAccounts: $tab !== 'missing');
        $ready = $import?->status === ServerAccountImport::READY;
        $tab = in_array($tab = $request->string('tab')->toString(), self::TABS, true) ? $tab : 'all';
        $search = mb_strtolower(mb_substr(trim($request->string('search')->toString()), 0, 100));
        $page = max(1, $request->integer('page', 1));
        $query = ['path' => $request->url(), 'query' => $request->only(['tab', 'search'])];

        $rows = match (true) {
            ! $ready => new LengthAwarePaginator([], 0, self::PER_PAGE, $page, $query),
            $tab === 'missing' => $this->missing($server, $import, $search),
            default => $this->accounts($server, $import, $tab, $search, $page, $query, $matcher),
        };

        return Inertia::render('admin/services/servers/import', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'hostname' => $server->hostname],
            'supported' => $drivers->has($server->driver) && $drivers->get($server->driver) instanceof ListsAccounts,
            'import' => $import === null ? null : [
                'status' => $import->status,
                'error' => $import->error,
                'account_count' => $import->account_count,
                'requested_by' => $import->requester?->name,
                'requested_at' => $import->created_at?->toIso8601String(),
                'fetched_at' => $import->fetched_at?->toIso8601String(),
                'expires_at' => $import->expires_at->toIso8601String(),
                'running' => $import->isRunning(),
            ],
            'counts' => $ready ? [
                'all' => $import->account_count,
                AccountImportMatcher::PROPOSED => $import->proposed_count,
                AccountImportMatcher::UNMATCHED => $import->unmatched_count,
                AccountImportMatcher::RESELLER => $import->reseller_count,
                AccountImportMatcher::LINKED => $import->linked_count,
                'missing' => count($import->missing ?? []),
            ] : null,
            'rows' => $rows,
            'filters' => ['tab' => $tab, 'search' => $search],
            'createProductUrl' => Route::has('admin.catalog.products.create') ? route('admin.catalog.products.create') : null,
            'included' => count(array_filter($import === null ? [] : ($import->selections ?? []), fn (array $choice): bool => (bool) ($choice['include'] ?? false))),
            'productOptions' => $ready ? $plan->productOptions($server) : [],
            'cycles' => self::cycles(),
        ]);
    }

    /**
     * Start reading the server's accounts on the queue (FetchAccountsForImport).
     */
    public function fetch(Request $request, Server $server, FetchAccountsForImport $fetch): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $fetch->handle($server, $request->user());
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Reading the accounts on :server. The list appears when it is ready.', ['server' => $server->name])]);

        return to_route('admin.services.servers.import.show', $server);
    }

    /**
     * "Match again" on the queue (FetchAccountsForImport::rematch()); the page shows it running.
     */
    public function rematch(Server $server, FetchAccountsForImport $fetch): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $fetch->rematch($server);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Matching the accounts on :server again. The list updates when it is done.', ['server' => $server->name])]);

        return to_route('admin.services.servers.import.show', $server);
    }

    /**
     * Record staff choices for accounts (tick or untick, client, product, cycle, next due date), or tick every proposed
     * account at once. Only usernames in the fetched list, open clients and products that provision on this server are
     * accepted; nothing else about an account ever comes from the request.
     */
    public function select(Request $request, Server $server, AccountImportPlan $plan): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $import = $this->ready($server);
        $data = $request->validate([
            'proposed' => ['sometimes', 'accepted'],
            'usernames' => ['required_without:proposed', 'array', 'max:500'],
            'usernames.*' => ['string', 'max:32'],
            'include' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'product_id' => ['sometimes', 'integer', Rule::in(array_column($plan->productOptions($server), 'id'))],
            'cycle' => ['sometimes', Rule::in(array_column(self::cycles(), 'value'))],
            'next_due_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+5 years'],
        ], [
            'client_id.exists' => __('Choose an active client.'),
            'product_id.in' => __('Choose a product that provisions on :server.', ['server' => $server->name]),
            'next_due_date.after_or_equal' => __('The next due date cannot be in the past.'),
        ]);

        $accounts = collect($import->accounts ?? [])->keyBy('username');
        $usernames = isset($data['proposed'])
            ? $accounts->where('category', AccountImportMatcher::PROPOSED)->keys()->all()
            : array_values(array_intersect(array_unique($data['usernames']), $accounts->keys()->all()));
        $choice = isset($data['proposed']) ? ['include' => true] : array_intersect_key($data, array_flip(['include', 'client_id', 'product_id', 'cycle', 'next_due_date']));
        $selections = $import->selections ?? [];

        foreach ($usernames as $username) {
            $selections[$username] = [...($selections[$username] ?? []), ...$choice];
        }

        $import->update(['selections' => $selections]);

        return back();
    }

    /**
     * Open, active clients for the client picker on this page (name, company or email).
     */
    public function clients(Request $request, Server $server): JsonResponse
    {
        $this->authorize('services.servers.import');

        $term = mb_substr(trim($request->string('q')->toString()), 0, 100);

        return response()->json(Client::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($query) => $query->search($term))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'currency'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'display_name' => $client->display_name, 'email' => $client->email, 'currency' => $client->currency])
            ->values());
    }

    /**
     * Every ticked account as it would be imported, matched and checked again against today, with totals.
     */
    public function review(Server $server, AccountImportPlan $plan): Response
    {
        $this->authorize('services.servers.import');

        $rows = $plan->rows($server, $this->ready($server));
        $totals = [];

        foreach ($rows as $row) {
            if ($row['decision']['problems'] === []) {
                $currency = $row['decision']['client']['currency'];
                $totals[$currency] = ($totals[$currency] ?? 0) + (int) $row['decision']['amount'];
            }
        }

        return Inertia::render('admin/services/servers/import-review', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'hostname' => $server->hostname],
            'rows' => $rows,
            'totals' => array_map(fn (string $currency, int $amount): array => ['currency' => $currency, 'amount' => $amount], array_keys($totals), $totals),
            'cycles' => self::cycles(),
        ]);
    }

    /**
     * Create the services (ImportServerAccounts) and show the per-row report on the import page.
     */
    public function apply(Request $request, Server $server, ImportServerAccounts $importer): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $report = $importer->handle($server, $this->ready($server), $request->user());
        $imported = count(array_filter($report, fn (array $line): bool => $line['imported']));

        Inertia::flash('importReport', $report);
        Inertia::flash('toast', ['type' => $imported === count($report) ? 'success' : 'info', 'message' => __(':imported of :total accounts imported from :server.', ['imported' => $imported, 'total' => count($report), 'server' => $server->name])]);

        return to_route('admin.services.servers.import.show', $server);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function cycles(): array
    {
        return array_map(fn (BillingCycle $cycle): array => ['value' => $cycle->value, 'label' => ucfirst($cycle->value)], BillingCycle::recurring());
    }

    /**
     * The server's fetched list, with its accounts.
     *
     * @throws ValidationException when there is none to work from
     */
    private function ready(Server $server): ServerAccountImport
    {
        $import = FetchAccountsForImport::latest($server, withAccounts: true);

        if ($import?->status !== ServerAccountImport::READY) {
            throw ValidationException::withMessages(['import' => __('Fetch the accounts on :server again: the list is missing or expired.', ['server' => $server->name])]);
        }

        return $import;
    }

    /**
     * One page of the stored accounts, with that page re-checked against today: a live service that now holds the
     * username, and the current names of the matched client (if still open) and product (if it still provisions here).
     *
     * @param  array{path: string, query: array<string, mixed>}  $query
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    private function accounts(Server $server, ServerAccountImport $import, string $tab, string $search, int $page, array $query, AccountImportMatcher $matcher): LengthAwarePaginator
    {
        $listed = array_values(array_filter($import->accounts ?? [], fn (array $row): bool => ($tab === 'all' || $row['category'] === $tab)
            && ($search === '' || collect([$row['username'], $row['domain'] ?? null, $row['email'] ?? null])->contains(fn (?string $value): bool => $value !== null && str_contains(mb_strtolower($value), $search)))));
        $slice = array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $live = Service::query()->with('client:id,first_name,last_name,company_name')
            ->where('server_id', $server->id)->whereIn('status', ServiceStatus::occupyingValues())
            ->whereIn('username', array_column($slice, 'username'))
            ->get(['id', 'client_id', 'product_id', 'product_name', 'domain', 'username', 'status'])->keyBy('username');
        $choices = $import->selections ?? [];
        $slice = array_map(fn (array $row): array => [...$row,
            'client_id' => $choices[$row['username']]['client_id'] ?? $row['client_id'],
            'product_id' => $choices[$row['username']]['product_id'] ?? $row['product_id'],
            'chosen' => array_intersect_key($choices[$row['username']] ?? [], array_flip(['cycle', 'next_due_date'])),
            'include' => (bool) ($choices[$row['username']]['include'] ?? false),
        ], $slice);
        $clients = Client::query()->whereKey(array_filter(array_column($slice, 'client_id')))->where('status', '!=', 'closed')
            ->get(['id', 'first_name', 'last_name', 'company_name'])->keyBy('id');
        $products = $matcher->serverProducts($server);

        $fresh = array_map(function (array $row) use ($live, $clients, $products): array {
            $service = $live->get($row['username']);
            $client = $clients->get($row['client_id'] ?? 0);
            $product = $products[$row['product_id'] ?? 0] ?? null;
            $notes = array_filter([
                $row['note'],
                $service !== null && $row['category'] !== AccountImportMatcher::LINKED ? __('A service took this account after the list was fetched.') : null,
                $service === null && $row['category'] === AccountImportMatcher::LINKED ? __('The service that held this account is no longer live. Match again.') : null,
                $row['client_id'] !== null && $client === null ? __('The matched client was closed or removed since. Match again.') : null,
                $row['product_id'] !== null && $product === null ? __('The matched product no longer provisions on this server. Match again.') : null,
            ]);

            return [...array_diff_key($row, array_flip(['client_id', 'product_id', 'service_id'])),
                'category' => $service !== null ? AccountImportMatcher::LINKED : $row['category'],
                'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->display_name],
                'product' => $product === null ? null : ['id' => $product->id, 'name' => $product->name],
                'service' => $service === null ? null : ['id' => $service->id, 'label' => $service->label(), 'client' => $service->client->display_name],
                'note' => $notes === [] ? null : implode(' ', $notes),
            ];
        }, $slice);

        return new LengthAwarePaginator($fresh, count($listed), self::PER_PAGE, $page, $query);
    }

    /**
     * The live services whose account the server did not report, still live and as they are now.
     *
     * @return LengthAwarePaginator<int, array{username: string|null, domain: string|null, status: string, service: array{id: int, label: string, client: string}}>
     */
    private function missing(Server $server, ServerAccountImport $import, string $search): LengthAwarePaginator
    {
        return Service::query()->with('client:id,first_name,last_name,company_name')
            ->whereKey($import->missing ?? [])
            ->where('server_id', $server->id)
            ->whereIn('status', ServiceStatus::occupyingValues())
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Service $service): array => [
                'username' => $service->username,
                'domain' => $service->domain,
                'status' => $service->status->value,
                'service' => ['id' => $service->id, 'label' => $service->label(), 'client' => $service->client->display_name],
            ]);
    }
}
