<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Contracts\Session\Session;

/**
 * The client account a signed-in user shops for on public store pages (which do not run the
 * client.area middleware): the account selected in the client area, else the first open one.
 */
class CustomerResolver
{
    public function clientFor(?User $user, ?Session $session = null): ?Client
    {
        if ($user === null) {
            return null;
        }

        $clients = $user->clients()->where('status', '!=', 'closed');
        $selected = $session?->get(ClientContext::SESSION_KEY);

        return ($selected ? (clone $clients)->whereKey($selected)->first() : null) ?? $clients->first();
    }
}
