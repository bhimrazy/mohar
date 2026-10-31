<?php

namespace App\Support\Billing;

use App\Contracts\Billing\CancelsHostedPayment;
use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\DescribesManualRefund;
use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Contracts\Integrations\IntegrationDriver;
use App\Contracts\Integrations\ProvidesLogo;
use App\Contracts\Integrations\ValidatesSettings;
use App\Data\Integrations\SettingField;
use App\Support\Integrations\DriverRegistry;
use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Every installed payment gateway driver (ADR 0012, ADR 0030 M3). Kernel-owned, so a package registers in its own
 * boot() whether or not Billing is loaded:
 *
 *   $this->app->make(GatewayDriverRegistry::class)->register('khalti', KhaltiDriver::class);
 *
 * The key is also `gateways.key`, `credit_ledger.gateway` and the webhook and return URL segment, so it never
 * changes once a gateway has been used.
 *
 * @api
 *
 * @extends DriverRegistry<GatewayDriver>
 */
final class GatewayDriverRegistry extends DriverRegistry
{
    /**
     * Keys no driver may take: `import` marks imported opening-balance entries in credit_ledger.gateway (every entry
     * before #263, and beside the idempotency key until #284), so a driver's provider references must never share it.
     */
    public const RESERVED_KEYS = ['import'];

    public const HOSTED = 'hosted';

    public const TEST_CONNECTION = 'test_connection';

    public const LOGO = 'logo';

    public const REFUND_LOOKUP = 'refund_lookup';

    public const CANCEL_HOSTED = 'cancel_hosted';

    public const RESUME_HOSTED = 'resume_hosted';

    public const MANUAL_REFUND_NOTE = 'manual_refund_note';

    public const VALIDATES_SETTINGS = 'validates_settings';

    /**
     * @param  class-string<GatewayDriver>|Closure(Container): GatewayDriver  $driver
     *
     * @throws LogicException for a reserved key, a malformed one, or a duplicate without $replace
     */
    public function register(string $key, string|Closure $driver, bool $replace = false): void
    {
        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw new LogicException("The gateway key [{$key}] is reserved; give the driver another key.");
        }

        parent::register($key, $driver, $replace);
    }

    protected function contract(): string
    {
        return GatewayDriver::class;
    }

    /**
     * The optional capability interfaces of ADR 0009 Amendment (#491). CAPTURE, REFUND, TOKENIZE, WEBHOOK and
     * REMOTE_INPUT stay flags read through GatewayDriver::supports() (ADR 0012).
     */
    public function capabilities(): array
    {
        return [
            self::HOSTED => HostedPaymentDriver::class,
            self::TEST_CONNECTION => ConnectionTester::class,
            self::LOGO => ProvidesLogo::class,
            self::REFUND_LOOKUP => LooksUpRefunds::class,
            self::CANCEL_HOSTED => CancelsHostedPayment::class,
            self::RESUME_HOSTED => ResumesHostedPayment::class,
            self::MANUAL_REFUND_NOTE => DescribesManualRefund::class,
            self::VALIDATES_SETTINGS => ValidatesSettings::class,
        ];
    }

    /**
     * The driver's staff settings.
     *
     * @return list<SettingField>
     */
    public function fields(string $key): array
    {
        return self::fieldsOf($this->get($key));
    }

    /**
     * A driver's settings as SettingField objects. A driver may still return the array shape that gateways used before
     * kernel 0.x M3 (deprecated, read through SettingField::fromArray()); new drivers return SettingField.
     *
     * @return list<SettingField>
     */
    public static function fieldsOf(GatewayDriver $driver): array
    {
        $fields = [];

        foreach ($driver->settingsFields() as $field) {
            $fields[] = self::field($field);
        }

        return $fields;
    }

    /**
     * @param  SettingField|array{key: string, label: string, type?: string, help?: string, options?: array<string, string>, required?: bool, default?: string|bool}  $field
     */
    private static function field(SettingField|array $field): SettingField
    {
        return $field instanceof SettingField ? $field : SettingField::fromArray($field);
    }

    /**
     * Every settings field must build and keys must be unique, so a broken package is hidden (unavailable()) rather
     * than failing the gateway pages.
     *
     * @param  GatewayDriver  $driver
     */
    protected function validate(IntegrationDriver $driver): void
    {
        $keys = array_map(fn (SettingField $field): string => $field->key, self::fieldsOf($driver));

        if (count($keys) !== count(array_unique($keys))) {
            throw new LogicException("The gateway driver [{$driver->key()}] declares a setting key twice.");
        }
    }
}
