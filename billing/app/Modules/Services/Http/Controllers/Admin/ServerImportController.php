<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Servers → server → Import accounts (cPanel spec §10): fetch what the server holds and show how each account
 * matches clients, products and services here. Read-only on both sides; importing is a separate, confirmed step.
 */
class ServerImportController extends Controller
{
    public const TABS = ['all', AccountImportMatcher::PROPOSED, AccountImportMatcher::UNMATCHED, AccountImportMatcher::RESELLER, AccountImportMatcher::LINKED, 'missing'];

    private const PER_PAGE = 50;

    public function show(Request $request, Server $server, ProvisioningDriverRegistry $drivers, AccountImportMatcher $matcher): Response
    {
        $this->authorize('services.servers.import');

        $import = FetchAccountsForImport::latest($server);
        $tab = in_array($tab = $request->string('tab')->toString(), self::TABS, true) ? $tab : 'all';
        $search = mb_strtolower(mb_substr(trim($request->string('search')->toString()), 0, 100));
        $accounts = $import?->status === ServerAccountImport::READY ? ($import->accounts ?? []) : [];
        $rows = $import?->status === ServerAccountImport::READY ? $matcher->classify($server, $accounts) : [];
        $missing = $import?->status === ServerAccountImport::READY ? $matcher->missing($server, $accounts) : collect();

        $counts = ['all' => count($rows), 'missing' => $missing->count()];

        foreach ([AccountImportMatcher::PROPOSED, AccountImportMatcher::UNMATCHED, AccountImportMatcher::RESELLER, AccountImportMatcher::LINKED] as $category) {
            $counts[$category] = count(array_filter($rows, fn (array $row): bool => $row['category'] === $category));
        }

        $listed = $tab === 'missing'
            ? $missing->map(fn (Service $service): array => [
                'username' => $service->username,
                'domain' => $service->domain,
                'status' => $service->status->value,
                'service' => ['id' => $service->id, 'label' => $service->label(), 'client' => $service->client->display_name],
            ])->all()
            : array_filter($rows, fn (array $row): bool => $tab === 'all' || $row['category'] === $tab);

        if ($search !== '') {
            $listed = array_filter($listed, fn (array $row): bool => collect([$row['username'], $row['domain'] ?? null, $row['email'] ?? null])
                ->contains(fn (?string $value): bool => $value !== null && str_contains(mb_strtolower($value), $search)));
        }

        $page = max(1, $request->integer('page', 1));
        $listed = array_values($listed);

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
            'counts' => $counts,
            'rows' => new LengthAwarePaginator(array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($listed), self::PER_PAGE, $page, [
                'path' => $request->url(),
                'query' => $request->only(['tab', 'search']),
            ]),
            'filters' => ['tab' => $tab, 'search' => $search],
        ]);
    }

    /**
     * Start reading the server's accounts on the queue (FetchAccountsForImport).
     */
    public function fetch(Request $request, Server $server, FetchAccountsForImport $fetch): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $fetch->handle($server, $request->user());
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Reading the accounts on :server. The list appears here when it is ready.', ['server' => $server->name])]);

        return to_route('admin.services.servers.import.show', $server);
    }
}
