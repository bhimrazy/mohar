<?php

namespace App\Modules\Services\Http\Resources;

use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Server without secrets (only whether each credential and secret setting is set).
 *
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'assigned_ips' => $this->assigned_ips ?? [],
            'driver' => $this->driver,
            'username' => $this->username,
            'has_password' => $this->password !== null,
            'has_api_token' => $this->api_token !== null,
            'has_access_hash' => $this->access_hash !== null,
            'secure' => $this->secure,
            'port' => $this->port,
            'max_accounts' => $this->max_accounts,
            'active_accounts' => $this->active_accounts_count,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'nameservers' => $this->nameservers ?? [],
            'monthly_cost' => $this->monthly_cost,
            'currency' => $this->currency,
            'datacenter' => $this->datacenter,
            'status_url' => $this->status_url,
            'settings' => app(ServerSettings::class)->forForm($this->resource),
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($group): array => ['id' => $group->id, 'name' => $group->name])->values()),
        ];
    }
}
