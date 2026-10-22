<?php

namespace App\Modules\Domains\Models;

use App\Modules\Domains\Database\Factories\TldFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $extension
 * @property string|null $display_unicode
 * @property string|null $registrar_key
 * @property bool $auto_register
 * @property bool $epp_required
 * @property int $min_years
 * @property int $max_years
 * @property bool $allow_register
 * @property bool $allow_transfer
 * @property bool $registered_by_client the client registers it at the registry; it is never sold here
 * @property int $grace_days
 * @property int $grace_fee
 * @property int $redemption_days
 * @property int $redemption_fee
 * @property int|null $renew_window_days
 * @property bool $addon_dns
 * @property bool $addon_id_protection
 * @property bool $addon_email_forwarding
 * @property bool $is_spotlight
 * @property string|null $badge
 * @property array<int, array<string, mixed>>|null $additional_fields
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(TldFactory::class)]
#[Fillable([
    'extension', 'display_unicode', 'registrar_key', 'auto_register', 'epp_required', 'min_years', 'max_years',
    'allow_register', 'allow_transfer', 'registered_by_client', 'grace_days', 'grace_fee', 'redemption_days', 'redemption_fee',
    'renew_window_days', 'addon_dns', 'addon_id_protection', 'addon_email_forwarding', 'is_spotlight', 'badge',
    'additional_fields', 'sort_order', 'is_active',
])]
class Tld extends Model
{
    /** @use HasFactory<TldFactory> */
    use HasFactory;

    public const BADGES = ['hot', 'new', 'sale'];

    /**
     * Whether the client registers names under this extension at the registry themselves. On by default for .np
     * and its second levels (com.np, org.np …), whose registry registers names only for their owners.
     */
    public static function registeredByClientByDefault(string $extension): bool
    {
        return $extension === '.np' || str_ends_with($extension, '.np');
    }

    /** Second levels the .np registry issues names under (register.com.np/np-ccTLDs, 2026-10-20). */
    public const NP_SECOND_LEVELS = ['com', 'coop', 'edu', 'gov', 'info', 'mil', 'name', 'net', 'org'];

    /**
     * Why the .np registry cannot have issued this name (it registers only under its second levels), or null.
     */
    public static function registryLevelHint(string $domain): ?string
    {
        $labels = explode('.', rtrim(strtolower($domain), '.'));

        if (end($labels) !== 'np' || (count($labels) >= 3 && in_array($labels[count($labels) - 2], self::NP_SECOND_LEVELS, true))) {
            return null;
        }

        return __('.np names are registered under .com.np, .org.np and the other second levels. Search for yourname.com.np.');
    }

    protected static function booted(): void
    {
        static::creating(function (Tld $tld): void {
            $tld->registered_by_client ??= self::registeredByClientByDefault((string) $tld->extension);
        });
    }

    protected function casts(): array
    {
        return [
            'auto_register' => 'boolean',
            'epp_required' => 'boolean',
            'min_years' => 'integer',
            'max_years' => 'integer',
            'allow_register' => 'boolean',
            'allow_transfer' => 'boolean',
            'registered_by_client' => 'boolean',
            'grace_days' => 'integer',
            'grace_fee' => 'integer',
            'redemption_days' => 'integer',
            'redemption_fee' => 'integer',
            'renew_window_days' => 'integer',
            'addon_dns' => 'boolean',
            'addon_id_protection' => 'boolean',
            'addon_email_forwarding' => 'boolean',
            'is_spotlight' => 'boolean',
            'additional_fields' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Normalise an extension to its stored form: lowercase, one leading dot, no trailing dot.
     */
    public static function normaliseExtension(string $extension): string
    {
        return '.'.trim(mb_strtolower(trim($extension)), '.');
    }

    /**
     * @return HasMany<TldPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TldPrice::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('extension');
    }
}
