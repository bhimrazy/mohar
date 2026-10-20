<?php

namespace App\Modules\Domains\Models;

use App\Modules\Core\Models\Client;
use App\Modules\Domains\Database\Factories\DomainFactory;
use App\Modules\Domains\Enums\DomainStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $client_id
 * @property int|null $order_id
 * @property int $tld_id
 * @property string $name ASCII / punycode, lowercase
 * @property string|null $active_name equals name while the status is non-terminal (unique)
 * @property string $name_unicode
 * @property string $sld
 * @property string $tld
 * @property string $type register|transfer
 * @property string|null $registrar_key null = manual handling
 * @property DomainStatus $status
 * @property CarbonImmutable|null $registration_date
 * @property CarbonImmutable|null $expiry_date
 * @property CarbonImmutable $next_due_date
 * @property CarbonImmutable $next_invoice_date
 * @property int|null $billing_anchor_day
 * @property int $period_years
 * @property int $first_payment_amount
 * @property int $recurring_amount
 * @property string $currency
 * @property int|null $promotion_id
 * @property string|null $gateway_key
 * @property bool $auto_renew
 * @property bool $id_protection
 * @property bool $dns_management
 * @property bool $email_forwarding
 * @property bool|null $registrar_lock
 * @property list<string>|null $nameservers
 * @property string|null $epp_code encrypted, transfer-in only
 * @property bool $is_premium
 * @property int|null $premium_renew_cost
 * @property int|null $linked_service_id
 * @property string|null $linked_service_status
 * @property CarbonImmutable|null $linked_service_due_date
 * @property int|null $provision_invoice_id invoice whose payment queued the registration/transfer
 * @property array<string, mixed>|null $additional_fields
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_sync_status ok|error|unsupported
 * @property string|null $last_error
 * @property bool $needs_review registrar outcome unknown or inconsistent; staff must verify before any retry
 * @property array<string, mixed>|null $registrar_meta
 * @property CarbonImmutable|null $transfer_stale_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Client $client
 * @property-read Tld $tldModel
 * @property-read NpRegistration|null $npRegistration
 */
#[UseFactory(DomainFactory::class)]
#[Hidden(['epp_code'])]
#[Fillable([
    'client_id', 'order_id', 'tld_id', 'name', 'name_unicode', 'sld', 'tld', 'type', 'registrar_key', 'status',
    'registration_date', 'expiry_date', 'next_due_date', 'next_invoice_date', 'billing_anchor_day', 'period_years',
    'first_payment_amount', 'recurring_amount', 'currency', 'promotion_id', 'gateway_key', 'auto_renew',
    'id_protection', 'dns_management', 'email_forwarding', 'registrar_lock', 'nameservers', 'epp_code',
    'is_premium', 'premium_renew_cost', 'linked_service_id', 'linked_service_status', 'linked_service_due_date',
    'provision_invoice_id', 'additional_fields', 'last_synced_at', 'last_sync_status', 'last_error', 'needs_review',
    'registrar_meta', 'transfer_stale_notified_at',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, SoftDeletes;

    public const TYPES = ['register', 'transfer'];

    protected static function booted(): void
    {
        static::saving(function (Domain $domain): void {
            $domain->active_name = $domain->status->isTerminal() ? null : $domain->name;
        });

        static::deleting(function (Domain $domain): void {
            $domain->active_name = null;
            $domain->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registration_date' => 'date',
            'expiry_date' => 'date',
            'next_due_date' => 'date',
            'next_invoice_date' => 'date',
            'linked_service_due_date' => 'date',
            'billing_anchor_day' => 'integer',
            'period_years' => 'integer',
            'first_payment_amount' => 'integer',
            'recurring_amount' => 'integer',
            'auto_renew' => 'boolean',
            'id_protection' => 'boolean',
            'dns_management' => 'boolean',
            'email_forwarding' => 'boolean',
            'registrar_lock' => 'boolean',
            'nameservers' => 'array',
            'epp_code' => 'encrypted',
            'is_premium' => 'boolean',
            'premium_renew_cost' => 'integer',
            'additional_fields' => 'array',
            'registrar_meta' => 'array',
            'last_synced_at' => 'datetime',
            'needs_review' => 'boolean',
            'transfer_stale_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The TLD catalog entry. Named tldModel because "tld" is the extension column.
     *
     * @return BelongsTo<Tld, $this>
     */
    public function tldModel(): BelongsTo
    {
        return $this->belongsTo(Tld::class, 'tld_id');
    }

    /**
     * The .np manual-registration workflow, for domains of the np_manual registrar.
     *
     * @return HasOne<NpRegistration, $this>
     */
    public function npRegistration(): HasOne
    {
        return $this->hasOne(NpRegistration::class);
    }

    /**
     * @return HasMany<DomainContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(DomainContact::class);
    }

    /**
     * @return HasMany<DomainInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(DomainInvoice::class);
    }

    /**
     * @return HasMany<DomainReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(DomainReminder::class);
    }

    /**
     * @return HasMany<RegistrarCommandLog, $this>
     */
    public function commandLog(): HasMany
    {
        return $this->hasMany(RegistrarCommandLog::class);
    }

    public function isFree(): bool
    {
        return $this->recurring_amount === 0;
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<DomainStatus>|DomainStatus  $statuses
     */
    public function scopeWithStatus(Builder $query, array|DomainStatus $statuses): void
    {
        $statuses = is_array($statuses) ? $statuses : [$statuses];
        $query->whereIn('status', array_map(fn (DomainStatus $status): string => $status->value, $statuses));
    }

    /**
     * Domains in a live status whose expiry falls within the next $days days (inclusive).
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days, ?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();

        $query->withStatus([DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption])
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->addDays($days));
    }

    /**
     * Domains with an unverified registrar outcome, or a pending order whose registrar action failed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNeedingAttention(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('needs_review', true)
            ->orWhere(fn (Builder $query) => $query->withStatus([DomainStatus::Pending, DomainStatus::PendingRegistration])->whereNotNull('last_error')));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $like = '%'.mb_strtolower(trim($term)).'%';
        $query->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('name_unicode', $like));
    }
}
