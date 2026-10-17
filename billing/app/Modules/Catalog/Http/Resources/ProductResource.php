<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\DriverSecrets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product details for the admin edit form.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_group_id' => $this->product_group_id,
            'group' => $this->whenLoaded('group', fn () => $this->group->only(['id', 'name'])),
            'type' => $this->type->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'payment_type' => $this->payment_type->value,
            'status' => $this->status(),
            'is_hidden' => $this->is_hidden,
            'is_retired' => $this->is_retired,
            'is_featured' => $this->is_featured,
            'is_taxable' => $this->is_taxable,
            'requires_domain' => $this->requires_domain,
            'stock_enabled' => $this->stock_enabled,
            'stock_quantity' => $this->stock_quantity,
            'quantity_mode' => $this->quantity_mode->value,
            'recurring_cycles_limit' => $this->recurring_cycles_limit,
            'fixed_term_days' => $this->fixed_term_days,
            'fixed_term_email_key' => $this->fixed_term_email_key,
            'welcome_email_key' => $this->welcome_email_key,
            'upgrade_email_key' => $this->upgrade_email_key,
            'auto_setup' => $this->auto_setup->value,
            'provisioning_driver' => $this->provisioning_driver,
            'server_group_id' => $this->server_group_id,
            ...$this->maskedDriverConfig(),
            'allow_config_option_upgrades' => $this->allow_config_option_upgrades,
            'prorata_enabled' => $this->prorata_enabled,
            'prorata_day' => $this->prorata_day,
            'prorata_charge_next_month_day' => $this->prorata_charge_next_month_day,
            'free_domain' => $this->free_domain->value,
            'free_domain_cycles' => $this->free_domain_cycles ?? [],
            'free_domain_tlds' => $this->free_domain_tlds ?? [],
            'suspend_days_override' => $this->suspend_days_override,
            'terminate_days_override' => $this->terminate_days_override,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Driver settings with secret values blanked; the browser only learns which secrets are set.
     *
     * @return array{driver_config: array<string, string>, driver_secret_keys: list<string>}
     */
    private function maskedDriverConfig(): array
    {
        $masked = app(DriverSecrets::class)->mask($this->provisioning_driver, $this->driver_config);

        return ['driver_config' => $masked['config'], 'driver_secret_keys' => $masked['secret_keys']];
    }
}
