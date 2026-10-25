<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Database\Factories\ServerFactory;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Support\ServerSettings;
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
 * @property array<string, mixed>|null $settings driver-declared server settings (encrypted; may hold secrets)
 * @property int|null $active_accounts_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(ServerFactory::class)]
#[Hidden(['password', 'api_token', 'access_hash', 'settings'])]
#[Fillable([
    'name', 'hostname', 'ip_address', 'assigned_ips', 'driver', 'username', 'password', 'api_token', 'access_hash',
    'secure', 'port', 'max_accounts', 'is_active', 'is_default', 'nameservers', 'monthly_cost', 'currency',
    'datacenter', 'status_url', 'settings',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Where and how connections go. Stored credentials and a trusted certificate belong to the destination they were
     * saved for: changing any of these clears them (security review 2026-10-19, M1).
     */
    public const DESTINATION = ['driver', 'hostname', 'ip_address', 'port', 'secure'];

    /** The server-row credentials. */
    public const CREDENTIALS = ['password', 'api_token', 'access_hash'];

    /**
     * Backstop for writes outside SaveServer (which also audits the move): a new destination never keeps the
     * credentials, secret driver settings or trusted certificate saved for the old one.
     */
    protected static function booted(): void
    {
        static::updating(function (Server $server): void {
            if (! $server->isDirty(self::DESTINATION)) {
                return;
            }

            // Raw values: an encrypted attribute set during this save (even to the same plaintext) has new ciphertext.
            $untouched = fn (string $attribute): bool => ($server->getAttributes()[$attribute] ?? null) === $server->getRawOriginal($attribute);

            foreach (self::CREDENTIALS as $credential) {
                if ($untouched($credential)) {
                    $server->setAttribute($credential, null);
                }
            }

            if (! $untouched('settings')) {
                return;
            }

            // Secret driver settings (of the old and the new driver) and the pin, as SaveServer drops them.
            $fields = app(ServerSettings::class);
            $settings = $server->settings ?? [];
            $drop = [ServerSettings::CERTIFICATE_PIN, ...$fields->secretKeys((string) $server->getOriginal('driver'), $settings), ...$fields->secretKeys($server->driver, $settings)];
            $kept = array_diff_key($settings, array_flip($drop));

            if ($kept !== $settings) {
                $server->settings = $kept ?: null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'api_token' => 'encrypted',
            'access_hash' => 'encrypted',
            'settings' => 'encrypted:array',
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
     * The normalised destination, from the stored attributes overlaid with $attributes (e.g. a submitted form).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{driver: string, hostname: string, ip_address: string, port: ?int, secure: bool}
     */
    public function destination(array $attributes = []): array
    {
        $values = [...$this->only(self::DESTINATION), ...array_intersect_key($attributes, array_flip(self::DESTINATION))];
        $port = $values['port'] ?? null;

        return [
            'driver' => (string) ($values['driver'] ?? ''),
            'hostname' => strtolower(trim((string) ($values['hostname'] ?? ''))),
            'ip_address' => trim((string) ($values['ip_address'] ?? '')),
            'port' => $port === null || $port === '' ? null : (int) $port,
            'secure' => filter_var($values['secure'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Whether saving $attributes would point this stored server somewhere else.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function retargetedBy(array $attributes): bool
    {
        return $this->exists && $this->destination() !== $this->destination($attributes);
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
