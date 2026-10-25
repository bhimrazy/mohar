<?php

namespace App\Modules\Domains\Support;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Support\Dashboard\Dashboard;

/**
 * Domains' part of the admin client page ("admin-client" dashboard area): the count and the domains expiring first.
 */
class DomainsAdminClientSummary
{
    private const RECENT = 5;

    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin-client', 'domains.count', function (?User $user, Client $client): array {
            $expiring = Domain::query()->where('client_id', $client->id)->expiringWithin(30)->count();

            return [
                'label' => 'Domains',
                'value' => Domain::query()->where('client_id', $client->id)->withStatus(DomainStatus::nonTerminal())->count(),
                'hint' => $expiring > 0 ? "{$expiring} expiring in 30 days" : null,
                'tone' => $expiring > 0 ? 'warning' : 'default',
                'href' => route('admin.domains.index', ['client' => $client->id]),
                'icon' => 'Globe',
            ];
        }, 'domains.domains.view', 22);

        $dashboard->widget('admin-client', 'domains.recent', 'list', fn (?User $user, Client $client): array => [
            'title' => 'Domains',
            'items' => Domain::query()->where('client_id', $client->id)->withStatus(DomainStatus::nonTerminal())
                ->orderByRaw('case when expiry_date is null then 1 else 0 end')->orderBy('expiry_date')->orderBy('id')
                ->limit(self::RECENT)
                ->get(['id', 'name_unicode', 'status', 'expiry_date', 'auto_renew'])
                ->map(fn (Domain $domain): array => [
                    'title' => $domain->name_unicode,
                    'subtitle' => $domain->auto_renew ? 'Auto-renew on' : 'Auto-renew off',
                    'href' => route('admin.domains.show', $domain),
                    'status' => $domain->status->value,
                    'date' => $domain->expiry_date?->toDateString(),
                    'dateLabel' => 'Expires',
                ])->values()->all(),
            'href' => route('admin.domains.index', ['client' => $client->id]),
            'empty' => 'No domains yet.',
        ], 'domains.domains.view', 32);
    }
}
