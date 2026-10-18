<?php

namespace App\Modules\Services\Http\Resources;

use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;
        $overdue = $this->next_due_date !== null
            && $this->next_due_date->lt(CarbonImmutable::today())
            && in_array($this->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        return [
            'id' => $this->id,
            'label' => $this->label(),
            'domain' => $this->domain,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'status' => $this->status->value,
            'cycle' => $this->cycle->value,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'recurring_amount' => $this->recurring_amount,
            'first_payment_amount' => $this->first_payment_amount,
            'registration_date' => $this->registration_date->toDateString(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'next_invoice_date' => $this->next_invoice_date?->toDateString(),
            'is_overdue' => $overdue,
            'username' => $this->username,
            'dedicated_ip' => $this->dedicated_ip,
            'assigned_ips' => $this->assigned_ips ?? [],
            'suspension_reason' => $this->suspension_reason,
            'do_not_renew' => $this->do_not_renew,
            'termination_date' => $this->termination_date?->toDateString(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'server' => $this->whenLoaded('server', fn (): ?array => $this->server === null ? null : [
                'id' => $this->server->id,
                'name' => $this->server->name,
                'hostname' => $this->server->hostname,
                'nameservers' => $this->server->nameservers ?? [],
            ]),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn (): array => ['id' => $this->client->id, 'name' => $this->client->display_name]),
            ...($staff ? [
                'order_id' => $this->order_id,
                'server_id' => $this->server_id,
                'billing_anchor_day' => $this->billing_anchor_day,
                'renewals_invoiced' => $this->renewals_invoiced,
                'recurring_cycles_limit' => $this->recurring_cycles_limit,
                'suspended_at' => $this->suspended_at?->toIso8601String(),
                'suspend_exempt_until' => $this->suspend_exempt_until?->toDateString(),
                'suspend_days_override' => $this->suspend_days_override,
                'terminate_days_override' => $this->terminate_days_override,
                'terminate_at_period_end' => $this->terminate_at_period_end,
                'payment_gateway' => $this->payment_gateway,
                'config_options' => $this->config_options['selections'] ?? [],
                'admin_notes' => $this->admin_notes,
                'deleted_at' => $this->deleted_at?->toIso8601String(),
            ] : []),
        ];
    }
}
