<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The client account the signed-in user is currently acting for in the client area.
 * Resolved by the "client.area" middleware; inject it in client-area controllers.
 */
class ClientContext
{
    public const SESSION_KEY = 'current_client_id';

    private ?Client $client = null;

    private ?User $user = null;

    public function set(Client $client, User $user): void
    {
        $this->client = $client;
        $this->user = $user;
    }

    public function client(): Client
    {
        if ($this->client === null) {
            throw new AuthorizationException('No client account selected.');
        }

        return $this->client;
    }

    public function id(): int
    {
        return $this->client()->id;
    }

    public function isResolved(): bool
    {
        return $this->client !== null;
    }

    /**
     * Whether the user may use an area of the account: billing, services, domains, support, profile, contacts.
     * Owners may do everything; other users need the area listed in their pivot permissions.
     */
    public function can(string $area): bool
    {
        if ($this->client === null || $this->user === null) {
            return false;
        }

        $pivot = $this->user->clients()->whereKey($this->client->id)->first()?->pivot;

        if ($pivot === null) {
            return false;
        }

        return (bool) $pivot->getAttribute('is_owner')
            || in_array($area, json_decode((string) $pivot->getAttribute('permissions'), true) ?: [], true);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(string $area): void
    {
        if (! $this->can($area)) {
            throw new AuthorizationException("You do not have access to {$area} for this account.");
        }
    }
}
