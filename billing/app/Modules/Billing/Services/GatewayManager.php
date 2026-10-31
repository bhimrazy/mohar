<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\DescribesManualRefund;
use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Integrations\ProvidesLogo;
use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\SettingField;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Support\Settings;
use App\Support\Billing\GatewayDriverRegistry;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Billing's host for gateway drivers: their configuration rows, the DTO they receive, and the questions Billing asks
 * about them. The drivers themselves live in the kernel's GatewayDriverRegistry (ADR 0012, ADR 0030 M3); the
 * GatewayRegistry methods are kept for packages written before it.
 */
class GatewayManager implements GatewayRegistry
{
    public function __construct(private GatewayDriverRegistry $drivers, private Settings $settings) {}

    /**
     * @deprecated since kernel 0.x M3; register a class with GatewayDriverRegistry::register($key, $class) instead.
     * Kept as an adapter with its old semantics: the instance is used as is, and a key registered again is replaced.
     */
    public function register(GatewayDriver $driver): void
    {
        $this->drivers->register($driver->key(), fn (): GatewayDriver => $driver, replace: true);
    }

    public function drivers(): array
    {
        return $this->drivers->all();
    }

    public function has(string $key): bool
    {
        return $this->drivers->has($key);
    }

    /**
     * Whether new payments may start on $key (ADR 0030 A1). Settlement of existing ones asks settles().
     */
    public function enabled(string $key): bool
    {
        return $this->drivers->enabled($key);
    }

    public function driver(string $key): GatewayDriver
    {
        if (! $this->drivers->isRegistered($key)) {
            throw new BillingException(__('Unknown payment gateway [:key].', ['key' => $key]));
        }

        return $this->drivers->get($key);
    }

    public function hosted(string $key): ?HostedPaymentDriver
    {
        $driver = $this->resolved($key);

        return $driver instanceof HostedPaymentDriver ? $driver : null;
    }

    /**
     * The driver's staff settings.
     *
     * @return list<SettingField>
     */
    public function fields(string $key): array
    {
        return GatewayDriverRegistry::fieldsOf($this->driver($key));
    }

    /**
     * Configuration row for a driver (unsaved default when staff haven't configured it yet).
     */
    public function gateway(string $key): Gateway
    {
        $driver = $this->driver($key);

        return Gateway::query()->firstOrNew(['key' => $key], [
            'display_name' => $driver->name(),
            'is_active' => false,
            'show_on_order_form' => $key !== 'manual',
            'sort_order' => array_search($key, $this->drivers->keys(), true) ?: 0,
        ]);
    }

    /**
     * Runs a driver call for $config's gateway with its configured secrets (GatewayConfig::secrets()) in the redaction
     * scope (ADR 0023 §5): log lines written during the call, and an exception that leaves it when it is reported or
     * stored as a failed job, are masked by those values too. Every Billing call to a gateway driver method that reaches the
     * provider goes through it; tests/Arch/LogRedactionTest.php fails one that does not.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function during(GatewayConfig $config, Closure $call): mixed
    {
        return RedactionScope::during(RedactionSet::of($config->secrets()), $call);
    }

    /**
     * The DTO drivers receive.
     */
    public function config(string|Gateway $gateway): GatewayConfig
    {
        $gateway = is_string($gateway) ? $this->gateway($gateway) : $gateway;
        $settings = $gateway->config ?? [];

        return new GatewayConfig(
            key: $gateway->key,
            displayName: $gateway->display_name,
            settings: $settings,
            sandbox: filter_var($settings['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN),
            convertToCurrency: $gateway->convert_to_currency,
            secretSettingKeys: $this->secretSettingKeys($gateway->key),
            httpTimeoutSeconds: $this->httpTimeoutSeconds(),
        );
    }

    /** At least 3 seconds; the default when the setting is not a number. */
    private function httpTimeoutSeconds(): int
    {
        $timeout = $this->settings->get('billing.gateways.http_timeout_seconds', GatewayConfig::DEFAULT_HTTP_TIMEOUT_SECONDS);

        return is_numeric($timeout) ? max(3, (int) $timeout) : GatewayConfig::DEFAULT_HTTP_TIMEOUT_SECONDS;
    }

    /**
     * @return list<string>
     */
    private function secretSettingKeys(string $key): array
    {
        $fields = $this->has($key) ? $this->fields($key) : [];

        return array_values(array_map(fn (SettingField $field): string => $field->key, array_filter($fields, fn (SettingField $field): bool => $field->isSecret())));
    }

    /**
     * The provider logo a driver ships (ProvidesLogo), for checkout and admin screens; null means "show the name".
     *
     * @return array{url: string, dark_url: string|null, alt: string}|null
     */
    public function logo(string $key): ?array
    {
        $driver = $this->resolved($key);

        return $driver instanceof ProvidesLogo ? $driver->logo()?->toArray() : null;
    }

    /**
     * The driver's own refund instructions when it has no refund API (DescribesManualRefund), or null.
     */
    public function manualRefundNote(string $key): ?string
    {
        $driver = $this->resolved($key);

        return $driver instanceof DescribesManualRefund && ! $driver->supports(GatewayDriver::REFUND) ? $driver->manualRefundNote() : null;
    }

    /**
     * Whether the gateway takes test money: the built-in sandbox card driver, or a provider driver whose sandbox mode
     * (the "sandbox" setting) is on.
     */
    public function isSandbox(string|Gateway $gateway): bool
    {
        $key = is_string($gateway) ? $gateway : $gateway->key;

        return str_starts_with($key, 'sandbox') || ($this->has($key) && $this->config($gateway)->sandbox);
    }

    /**
     * Active gateways clients may pay with, in display order. A gateway left in sandbox mode is never offered
     * where test money is not allowed (production), even if it was activated before the deployment changed.
     *
     * @return Collection<int, Gateway>
     */
    public function clientGateways(): Collection
    {
        return Gateway::query()
            ->where('is_active', true)
            ->where('show_on_order_form', true)
            ->whereIn('key', $this->drivers->keys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->reject(fn (Gateway $gateway): bool => ! SandboxPolicy::allowed() && $this->isSandbox($gateway))
            ->values();
    }

    public function isActive(string $key): bool
    {
        return $this->has($key) && Gateway::query()->where('key', $key)->where('is_active', true)->exists();
    }

    /**
     * Whether webhooks and customer returns for $key are still processed (ADR 0030 A1): deactivating a gateway stops
     * new payments, not the settlement of ones it already started. An inactive gateway settles only while it has a
     * payment attempt or a transaction here; one never used, with no saved row, or whose driver is gone is refused.
     * Each EXISTS reads a unique index led by `gateway`.
     */
    public function settles(string $key): bool
    {
        if (! $this->drivers->isRegistered($key)) {
            return false;
        }

        $active = Gateway::query()->where('key', $key)->value('is_active');

        if ($active === null) {
            return false;
        }

        return (bool) $active
            || PaymentAttempt::query()->where('gateway', $key)->exists()
            || Transaction::query()->where('gateway', $key)->exists();
    }

    public function supports(string $key, string $capability): bool
    {
        return $this->has($key) && $this->driver($key)->supports($capability);
    }

    /** The driver for $key, or null when none is registered or it cannot be used. */
    private function resolved(string $key): ?GatewayDriver
    {
        return $this->drivers->has($key) ? $this->drivers->get($key) : null;
    }
}
