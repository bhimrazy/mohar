<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Database\Factories\ServerFactory;
use App\Modules\Services\Enums\ServiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provisioning target (control panel / hypervisor) accessed through a driver.
 *
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property string|null $ip_address
 * @property list<string>|null $assigned_ips
 * @property string $driver
 * @property string|null $username
 * @property string|null $password
 * @property string|null $api_token
 * @property string|null $access_hash
 * @property bool $secure
 * @property int|null $port
 * @property int $max_accounts
 * @property bool $is_active
 * @property bool $is_default
 * @property list<string>|null $nameservers
 * @property int $monthly_cost
 * @property string $currency
 * @property string|null $datacenter
 * @property string|null $status_url
 * @property int|null $active_accounts_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(ServerFactory::class)]
#[Hidden(['password', 'api_token', 'access_hash'])]
#[Fillable([
    'name', 'hostname', 'ip_address', 'assigned_ips', 'driver', 'username', 'password', 'api_token', 'access_hash',
    'secure', 'port', 'max_accounts', 'is_active', 'is_default', 'nameservers', 'monthly_cost', 'currency',
    'datacenter', 'status_url',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'api_token' => 'encrypted',
            'access_hash' => 'encrypted',
            'assigned_ips' => 'array',
            'nameservers' => 'array',
            'secure' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'max_accounts' => 'integer',
            'monthly_cost' => 'integer',
        ];
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return BelongsToMany<ServerGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ServerGroup::class, 'server_group_server')->withPivot('sort_order');
    }

    /**
     * Adds active_accounts_count: services occupying a slot (pending, active, suspended).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithActiveAccounts(Builder $query): void
    {
        $query->withCount(['services as active_accounts_count' => fn (Builder $services) => $services->whereIn('status', ServiceStatus::occupyingValues())]);
    }

    public function activeAccounts(): int
    {
        return $this->active_accounts_count ?? $this->services()->whereIn('status', ServiceStatus::occupyingValues())->count();
    }

    public function hasCapacity(): bool
    {
        return $this->max_accounts === 0 || $this->activeAccounts() < $this->max_accounts;
    }

    /**
     * Fill ratio used by least-full selection; unlimited servers count as empty.
     */
    public function fillRatio(): float
    {
        return $this->max_accounts === 0 ? 0.0 : $this->activeAccounts() / $this->max_accounts;
    }

    /**
     * All credential values, used to redact module logs.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->password, $this->api_token, $this->access_hash], fn (?string $value): bool => $value !== null && $value !== ''));
    }
}
