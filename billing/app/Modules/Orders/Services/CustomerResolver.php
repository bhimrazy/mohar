<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The client account a signed-in user shops for on public store pages (which do not run the client.area
 * middleware). Only accounts the login owns or holds "billing" access on qualify — ordering spends the
 * account's money and credit (same rule as ClientContext::can('billing')). Among those, the account
 * selected in the client area wins, else the first one.
 */
class CustomerResolver
{
    /** @var array<int, Collection<int, Client>> per-user cache for the request */
    private array $eligible = [];

    public function clientFor(?User $user, ?Session $session = null): ?Client
    {
        if ($user === null) {
            return null;
        }

        $clients = $this->orderableClients($user);
        $selected = $session?->get(ClientContext::SESSION_KEY);

        return ($selected ? $clients->firstWhere('id', (int) $selected) : null) ?? $clients->first();
    }

    public function canOrderFor(?User $user, int $clientId): bool
    {
        return $user !== null && $this->orderableClients($user)->contains('id', $clientId);
    }

    /**
     * @return Collection<int, Client>
     */
    private function orderableClients(User $user): Collection
    {
        return $this->eligible[$user->id] ??= $user->clients()
            ->where('status', '!=', 'closed')
            ->orderBy('clients.id')
            ->get()
            ->filter(function (Client $client): bool {
                $pivot = $client->getRelation('pivot');
                $areas = json_decode((string) $pivot->getAttribute('permissions'), true);

                return (bool) $pivot->getAttribute('is_owner') || in_array('billing', is_array($areas) ? $areas : [], true);
            })
            ->values();
    }
}
