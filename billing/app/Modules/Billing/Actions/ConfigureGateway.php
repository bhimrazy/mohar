<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Save a gateway's staff configuration. Secret settings left empty keep their stored value; deactivating can move
 * unpaid invoices to a replacement gateway.
 */
class ConfigureGateway
{
    public function __construct(private GatewayManager $gateways) {}

    /**
     * @param  array{display_name: string, is_active: bool, show_on_order_form: bool, sort_order: int, convert_to_currency: string|null}  $attributes
     * @param  array<string, string|null>  $settings
     */
    public function handle(string $key, array $attributes, array $settings, ?string $replacement = null): Gateway
    {
        $driver = $this->gateways->driver($key);
        $gateway = $this->gateways->gateway($key);
        $wasActive = $gateway->exists && $gateway->is_active;
        $config = $gateway->config ?? [];

        foreach ($driver->settingsFields() as $field) {
            $value = (string) ($settings[$field['key']] ?? '');

            if (! in_array($field['type'], ['secret', 'secret_text'], true) || $value !== '') {
                $config[$field['key']] = $value;
            }
        }

        return DB::transaction(function () use ($gateway, $attributes, $config, $wasActive, $key, $replacement): Gateway {
            $gateway->fill([...$attributes, 'config' => $config])->save();

            if ($wasActive && ! $gateway->is_active && $replacement !== null) {
                Invoice::query()->where('payment_gateway', $key)->whereIn('status', InvoiceStatus::payable())->update(['payment_gateway' => $replacement]);
            }

            return $gateway;
        });
    }
}
