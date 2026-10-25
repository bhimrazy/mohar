<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Events\Billing\GatewayConfigurationChanged;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\CertificateBundle;
use Illuminate\Support\Facades\DB;

/**
 * Save a gateway's staff configuration. Secret settings left empty keep their stored value; a certificate setting
 * changes only through an upload read by GatewayRequest (never from text); deactivating can move unpaid invoices to a
 * replacement gateway.
 */
class ConfigureGateway
{
    public function __construct(
        private GatewayManager $gateways,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  array{display_name: string, is_active: bool, show_on_order_form: bool, sort_order: int, convert_to_currency: string|null}  $attributes
     * @param  array<string, string|null>  $settings
     * @param  array<string, CertificateBundle>  $certificates  uploaded certificates, by setting key
     */
    public function handle(string $key, array $attributes, array $settings, ?string $replacement = null, ?int $userId = null, array $certificates = []): Gateway
    {
        if (str_starts_with($key, 'sandbox') && ! SandboxCardDriver::enabled() && $attributes['is_active']) {
            throw new BillingException(__('Sandbox gateways can\'t be activated in production.'));
        }

        $driver = $this->gateways->driver($key);
        $gateway = $this->gateways->gateway($key);
        $wasActive = $gateway->exists && $gateway->is_active;
        $config = $gateway->config ?? [];
        $changes = [];

        foreach (array_map(SettingField::fromArray(...), $driver->settingsFields()) as $field) {
            if ($field->type === FieldType::Certificate) {
                if (isset($certificates[$field->key])) {
                    $info = $certificates[$field->key]->info;
                    $before = $config[GatewayConfig::CERTIFICATES][$field->key]['not_after'] ?? null;
                    $config[$field->key] = $certificates[$field->key]->privateKeyPem;
                    $config[GatewayConfig::CERTIFICATES][$field->key] = $info->toArray();
                    $changes['settings.'.$field->key] = 'rotated';
                    $changes['settings.'.$field->key.'.expires'] = ['from' => $before, 'to' => $info->toArray()['not_after']];
                }

                continue;
            }

            $value = (string) ($settings[$field->key] ?? '');
            $secret = $field->isSecret();

            if (! $secret || $value !== '') {
                $before = (string) ($config[$field->key] ?? '');

                if ($before !== $value) {
                    // Secret values never reach the audit trail.
                    $changes['settings.'.$field->key] = $secret ? 'rotated' : ['from' => $before, 'to' => $value];
                }

                $config[$field->key] = $value;
            }
        }

        if ($attributes['is_active']) {
            $this->assertActivatable($driver, $config);
        }

        foreach ($attributes as $attribute => $value) {
            if ($gateway->getAttribute($attribute) != $value || ! $gateway->exists) {
                $changes[$attribute] = ['from' => $gateway->exists ? $gateway->getAttribute($attribute) : null, 'to' => $value];
            }
        }

        return DB::transaction(function () use ($gateway, $attributes, $config, $wasActive, $key, $replacement, $changes, $userId, $certificates): Gateway {
            // The daily expiry check records its warnings on the row; keep the ones it wrote since this form was read.
            $locked = $gateway->exists ? Gateway::query()->lockForUpdate()->find($gateway->id) : null;

            foreach ($locked?->config[GatewayConfig::CERTIFICATES] ?? [] as $field => $stored) {
                if (! isset($certificates[$field]) && is_array($stored) && isset($config[GatewayConfig::CERTIFICATES][$field])) {
                    $config[GatewayConfig::CERTIFICATES][$field] = $stored;
                }
            }

            $gateway->fill([...$attributes, 'config' => $config])->save();

            if ($wasActive && ! $gateway->is_active && $replacement !== null) {
                Invoice::query()->where('payment_gateway', $key)->whereIn('status', InvoiceStatus::payable())->update(['payment_gateway' => $replacement]);
            }

            if ($changes !== []) {
                $this->activity->log('Payment gateway '.$key.' changed', $gateway, properties: ['changes' => $changes]);
                AfterCommit::dispatch(new GatewayConfigurationChanged($key, $changes, $userId));
            }

            return $gateway;
        });
    }

    /**
     * An active gateway needs its required settings, and never runs in sandbox mode where test money is not
     * allowed (production): sandbox credentials would otherwise mark real invoices paid with test payments.
     *
     * @param  array<string, mixed>  $config
     */
    private function assertActivatable(GatewayDriver $driver, array $config): void
    {
        foreach (array_map(SettingField::fromArray(...), $driver->settingsFields()) as $field) {
            if ($field->type === FieldType::Boolean) {
                continue;
            }

            if ($field->required && trim((string) ($config[$field->key] ?? '')) === '') {
                throw new BillingException(__(':field is required to activate this gateway.', ['field' => $field->label]));
            }
        }

        if (! SandboxPolicy::allowed() && filter_var($config['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new BillingException(__('Sandbox mode can\'t be used in production. Turn it off and enter live credentials.'));
        }
    }
}
