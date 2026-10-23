<?php

namespace App\Modules\Domains\Http\Resources;

use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;
        $meta = $this->registrar_meta ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_unicode' => $this->name_unicode,
            'tld' => $this->tld,
            'type' => $this->type,
            'status' => $this->status->value,
            'registrar_key' => $this->registrar_key,
            'registered_by_client' => $this->isRegisteredByClient(),
            'registration_date' => $this->registration_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'next_due_date' => $this->next_due_date->toDateString(),
            'next_invoice_date' => $this->when($staff, fn (): string => $this->next_invoice_date->toDateString()),
            'days_until_expiry' => $this->expiry_date === null ? null : (int) CarbonImmutable::today()->diffInDays($this->expiry_date, false),
            'period_years' => $this->period_years,
            'first_payment_amount' => $this->when($staff, $this->first_payment_amount),
            'recurring_amount' => $this->recurring_amount,
            'currency' => $this->currency,
            'auto_renew' => $this->auto_renew,
            'id_protection' => $this->id_protection,
            'dns_management' => $this->dns_management,
            'email_forwarding' => $this->email_forwarding,
            'registrar_lock' => $this->registrar_lock,
            'nameservers' => $this->nameservers ?? [],
            'is_premium' => $this->is_premium,
            'contact_change_pending' => (bool) ($meta['contact_change_pending'] ?? false),
            'transfer_lock_until' => $meta['transfer_lock_until'] ?? null,
            'last_error' => $this->when($staff, $this->last_error),
            'needs_review' => $this->when($staff, $this->needs_review),
            'last_synced_at' => $this->when($staff, fn (): ?string => $this->last_synced_at?->toIso8601String()),
            'last_sync_status' => $this->when($staff, $this->last_sync_status),
            'linked_service_id' => $this->when($staff, $this->linked_service_id),
            'registrar_order_id' => $this->when($staff, fn (): string|int|null => is_int($meta['remote']['order_id'] ?? null) || is_string($meta['remote']['order_id'] ?? null) ? $meta['remote']['order_id'] : null),
            'has_epp_code' => $this->when($staff, fn (): bool => $this->epp_code !== null),
            'client' => $this->whenLoaded('client', fn (): array => ['id' => $this->client->id, 'display_name' => $this->client->display_name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
