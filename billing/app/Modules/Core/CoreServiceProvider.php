<?php

namespace App\Modules\Core;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\InMemoryMergeFieldRegistry;
use App\Modules\Core\Support\Settings;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;

class CoreServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'core';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(ActivityLogger::class);
        $this->app->scoped(ClientContext::class);
        $this->app->singleton(MergeFieldRegistry::class, InMemoryMergeFieldRegistry::class);
    }

    protected function bootModule(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Dashboard', 'admin.dashboard', 'LayoutGrid', 'General', 0));
        $navigation->add('admin', new NavItem('Clients', 'admin.clients.index', 'Users', 'Clients', 0, 'core.clients.view', 'admin.clients.*'));
        $navigation->add('client', new NavItem('Dashboard', 'client.dashboard', 'LayoutGrid', 'General', 0));
        $navigation->add('client', new NavItem('Account details', 'client.account.edit', 'UserRound', 'Setup', 90));

        $this->app->make(Dashboard::class)->card('admin', 'core.clients', fn (?User $user): array => [
            'label' => 'Active clients',
            'value' => Client::query()->where('status', 'active')->count(),
            'href' => route('admin.clients.index'),
            'icon' => 'Users',
        ], 'core.clients.view', 50);

        $this->app->make(PermissionRegistry::class)->register('Clients', [
            'core.clients.view' => 'View clients',
            'core.clients.manage' => 'Create and edit clients',
        ]);

        $this->app->make(GlobalSearch::class)->provider('Clients', fn (string $term): array => array_values(Client::query()
            ->search($term)->limit(5)->get()
            ->map(fn (Client $client): array => ['title' => $client->display_name, 'subtitle' => $client->email, 'href' => route('admin.clients.show', $client)])
            ->all()), 'core.clients.view');

        $this->app->make(MergeFieldRegistry::class)->register('client', function (int $id): array {
            $client = Client::query()->findOrFail($id);

            return [
                'client_id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'full_name' => trim($client->first_name.' '.$client->last_name),
                'company_name' => $client->company_name,
                'email' => $client->email,
                'currency' => $client->currency,
                'country' => $client->country,
            ];
        });
    }
}
