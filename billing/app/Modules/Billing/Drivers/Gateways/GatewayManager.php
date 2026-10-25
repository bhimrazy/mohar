<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Contracts\Billing\DescribesManualRefund;
use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Integrations\ProvidesLogo;
use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\SettingField;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Registry of gateway drivers (Billing's own and those registered by extension modules) and their configuration.
 */
class GatewayManager implements GatewayRegistry
{
    /**
     * Keys no driver may take: `import` marks imported opening-balance entries in credit_ledger.gateway (every entry
     * before #263, and beside the idempotency key until #284), so a driver's provider references must never share it.
     */
    public const RESERVED_KEYS = ['import'];

    /** The gateways.key and credit_ledger.gateway columns hold 50 characters. */
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, GatewayDriver> */
    private array $drivers = [];

    public function register(GatewayDriver $driver): void
    {
        $key = $driver->key();

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new LogicException("The gateway key [{$key}] must be 1 to 50 lowercase letters, digits or underscores.");
        }

        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw new LogicException("The gateway key [{$key}] is reserved; give the driver another key.");
        }

        $this->drivers[$key] = $driver;
    }

    public function drivers(): array
    {
        return $this->drivers;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    public function driver(string $key): GatewayDriver
    {
        return $this->drivers[$key] ?? throw new BillingException(__('Unknown payment gateway [:key].', ['key' => $key]));
    }

    public function hosted(string $key): ?HostedPaymentDriver
    {
        $driver = $this->drivers[$key] ?? null;

        return $driver instanceof HostedPaymentDriver ? $driver : null;
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
            'sort_order' => array_search($key, array_keys($this->drivers), true) ?: 0,
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
        );
    }

    /**
     * @return list<string>
     */
    private function secretSettingKeys(string $key): array
    {
        $fields = $this->has($key) ? array_map(SettingField::fromArray(...), $this->driver($key)->settingsFields()) : [];

        return array_values(array_map(fn (SettingField $field): string => $field->key, array_filter($fields, fn (SettingField $field): bool => $field->isSecret())));
    }

    /**
     * The provider logo a driver ships (ProvidesLogo), for checkout and admin screens; null means "show the name".
     *
     * @return array{url: string, dark_url: string|null, alt: string}|null
     */
    public function logo(string $key): ?array
    {
        $driver = $this->drivers[$key] ?? null;

        return $driver instanceof ProvidesLogo ? $driver->logo()?->toArray() : null;
    }

    /**
     * The driver's own refund instructions when it has no refund API (DescribesManualRefund), or null.
     */
    public function manualRefundNote(string $key): ?string
    {
        $driver = $this->drivers[$key] ?? null;

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
            ->whereIn('key', array_keys($this->drivers))
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

    public function supports(string $key, string $capability): bool
    {
        return $this->has($key) && $this->driver($key)->supports($capability);
    }
}
