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

    /** Areas a non-owner user can be granted on an account (client_user.permissions). */
    public const AREAS = ['billing', 'services', 'domains', 'support', 'profile', 'contacts'];

    private ?Client $client = null;

    private ?User $user = null;

    /** @var array<string, array{is_owner: bool, areas: list<string>}|false> "userId:clientId" => access; false = not linked */
    private array $access = [];

    public function set(Client $client, User $user): void
    {
        $this->client = $client;
        $this->user = $user;
        $this->access = [];
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
        return $this->client !== null && $this->allows($this->user, $this->client, $area);
    }

    /**
     * can() for a given user and account, e.g. in dashboard resolvers that receive both. Read once per request.
     */
    public function allows(?User $user, Client $client, string $area): bool
    {
        if ($user === null) {
            return false;
        }

        $key = $user->id.':'.$client->id;

        if (! array_key_exists($key, $this->access)) {
            $pivot = $user->clients()->whereKey($client->id)->first()?->pivot;
            $this->access[$key] = $pivot === null ? false : [
                'is_owner' => (bool) $pivot->getAttribute('is_owner'),
                'areas' => array_values(json_decode((string) $pivot->getAttribute('permissions'), true) ?: []),
            ];
        }

        $access = $this->access[$key];

        return $access !== false && ($access['is_owner'] || in_array($area, $access['areas'], true));
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
