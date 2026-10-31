<?php

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\GatewayManager;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Billing\GatewayConformance;
use App\Support\Billing\GatewayConformanceFailure;
use App\Support\Billing\GatewayDriverRegistry;
use Illuminate\Support\Facades\DB;

/** Every bundled driver, in the order the gateway pages list them: Billing's built-ins first, then the extensions. */
const BUNDLED_GATEWAYS = ['bank_transfer', 'manual', 'sandbox_card', 'cybersource', 'khalti', 'esewa', 'connectips', 'razorpay', 'stripe'];

function conformanceDriver(string $key, array $capabilities = [], array $fields = [], ?Closure $boot = null): GatewayDriver
{
    return new class($key, $capabilities, $fields, $boot) extends AbstractGatewayDriver
    {
        public function __construct(private string $driverKey, private array $flags, private array $fields, ?Closure $boot)
        {
            $boot?->__invoke();
        }

        public function key(): string
        {
            return $this->driverKey;
        }

        public function name(): string
        {
            return 'Conformance probe';
        }

        public function capabilities(): array
        {
            return $this->flags;
        }

        public function settingsFields(): array
        {
            return $this->fields;
        }
    };
}

test('every bundled gateway driver registers by key and class, built-ins first', function () {
    expect(app(GatewayDriverRegistry::class)->keys())->toBe(BUNDLED_GATEWAYS)
        ->and(array_keys(app(GatewayRegistry::class)->drivers()))->toBe(BUNDLED_GATEWAYS);
});

test('every bundled gateway driver passes the conformance kit', function (string $key) {
    GatewayConformance::assertDriver($key);
})->with(BUNDLED_GATEWAYS);

test('the kit refuses a flag without its method, the array settings shape, and I/O while building', function () {
    $registry = app(GatewayDriverRegistry::class);
    $registry->register('probe_refund', fn () => conformanceDriver('probe_refund', [GatewayDriver::REFUND]));
    $registry->register('probe_fields', fn () => conformanceDriver('probe_fields', fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'secret']]));
    $registry->register('probe_io', fn () => conformanceDriver('probe_io', boot: fn () => DB::table('gateways')->count()));
    $registry->register('probe_ok', fn () => conformanceDriver('probe_ok', fields: [new SettingField('api_key', 'API key', FieldType::Secret)]));

    expect(fn () => GatewayConformance::assertDriver('probe_refund'))->toThrow(GatewayConformanceFailure::class, 'declares [refund] but does not implement refund()')
        ->and(fn () => GatewayConformance::assertDriver('probe_fields'))->toThrow(GatewayConformanceFailure::class, 'not the deprecated array shape')
        ->and(fn () => GatewayConformance::assertDriver('probe_io'))->toThrow(GatewayConformanceFailure::class, 'did I/O')
        ->and(fn () => GatewayConformance::assertDriver('unknown_gateway'))->toThrow(GatewayConformanceFailure::class, 'No gateway driver is registered');

    GatewayConformance::assertDriver('probe_ok');
});

test('a key registered twice throws unless replaced; reserved and malformed keys are refused', function () {
    $registry = app(GatewayDriverRegistry::class);

    expect(fn () => $registry->register('manual', ManualDriver::class))->toThrow(LogicException::class, 'already registered')
        ->and(fn () => $registry->register('import', ManualDriver::class))->toThrow(LogicException::class, 'reserved')
        ->and(fn () => $registry->register('Bad-Key', ManualDriver::class))->toThrow(InvalidArgumentException::class);

    $registry->register('manual', ManualDriver::class, replace: true);
    expect($registry->get('manual'))->toBeInstanceOf(ManualDriver::class);
});

test('the deprecated instance registration still replaces a key and serves the same instance', function () {
    $driver = new ManualDriver;
    app(GatewayRegistry::class)->register($driver);

    expect(app(GatewayDriverRegistry::class)->get('manual'))->toBe($driver)
        ->and(app(GatewayManager::class)->driver('manual'))->toBe($driver);
});

test('settings fields read SettingField objects and the deprecated array shape alike', function () {
    $legacy = conformanceDriver('legacy', fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'secret', 'required' => true]]);
    $current = conformanceDriver('current', fields: [new SettingField('api_key', 'API key', FieldType::Secret, required: true)]);

    expect(GatewayDriverRegistry::fieldsOf($legacy))->toEqual(GatewayDriverRegistry::fieldsOf($current))
        ->and(app(GatewayManager::class)->fields('stripe'))->each->toBeInstanceOf(SettingField::class);
});

test('get() answers for a registered key, enabled() gates new work, and an unknown key is a Billing error', function () {
    $registry = app(GatewayDriverRegistry::class);

    expect($registry->enabled('stripe'))->toBeTrue()
        ->and($registry->enabled('nope'))->toBeFalse()
        ->and(fn () => $registry->get('nope'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(GatewayManager::class)->driver('nope'))->toThrow(BillingException::class, 'Unknown payment gateway [nope].');
});
