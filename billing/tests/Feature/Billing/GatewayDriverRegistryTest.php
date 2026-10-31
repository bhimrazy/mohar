<?php

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Support\Settings;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Billing\GatewayConformance;
use App\Support\Billing\GatewayConformanceFailure;
use App\Support\Billing\GatewayDriverRegistry;
use App\Support\Integrations\DriverRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
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

test('a stateful bundled driver is one instance per process, so its memo reaches the next call', function (string $key) {
    // Khalti's resume answer and Razorpay's webhook lookup hint are kept on the instance between two driver() calls.
    $manager = app(GatewayManager::class);

    expect($manager->driver($key))->toBe($manager->driver($key))
        ->and(app(GatewayDriverRegistry::class)->get($key))->toBe($manager->driver($key));
})->with(['khalti', 'razorpay', 'esewa', 'connectips', 'stripe', 'cybersource']);

test('a key the registry accepts also routes its webhook and return URLs', function () {
    // The registry and GatewayDriver::key() allow digits ([a-z0-9_]); a key must not register that its URLs cannot carry.
    app(GatewayDriverRegistry::class)->register('pay2', fn () => conformanceDriver('pay2'));

    GatewayConformance::assertDriver('pay2');

    $routes = app('router')->getRoutes();

    expect($routes->match(Request::create('/billing/webhooks/pay2', 'POST'))->getName())->toBe('billing.webhooks')
        ->and($routes->match(Request::create('/billing/gateways/pay2/return', 'GET'))->getName())->toBe('billing.gateways.return');
});

test('settlement and redaction reach a registered driver that may not start new work', function () {
    // What ADR 0030 M5a answers for a disabled extension: hidden from listings and new work, still settled.
    $registry = new class(app()) extends DriverRegistry
    {
        protected function contract(): string
        {
            return GatewayDriver::class;
        }

        public function capabilities(): array
        {
            return [];
        }

        public function has(string $key): bool
        {
            return false;
        }

        public function enabled(string $key): bool
        {
            return false;
        }
    };
    $registry->register('khalti', fn () => app(GatewayDriverRegistry::class)->get('khalti'));
    $manager = new GatewayManager($registry, app(Settings::class));
    $config = $manager->config(new Gateway(['key' => 'khalti', 'display_name' => 'Khalti', 'config' => ['secret_key' => 'live_secret_key_0123456789']]));

    expect($manager->has('khalti'))->toBeFalse()
        ->and($manager->enabled('khalti'))->toBeFalse()
        ->and($manager->hosted('khalti'))->toBeInstanceOf(KhaltiDriver::class)
        ->and($manager->driver('khalti'))->toBeInstanceOf(KhaltiDriver::class)
        ->and($config->secrets())->toBe(['live_secret_key_0123456789']);
});

test('the deprecated paths warn once per process', function () {
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        app(GatewayRegistry::class)->register(new ManualDriver);
        app(GatewayRegistry::class)->register(new ManualDriver);
        $legacy = conformanceDriver('legacy_once', fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'secret']]);
        GatewayDriverRegistry::fieldsOf($legacy);
        GatewayDriverRegistry::fieldsOf($legacy);
    } finally {
        restore_error_handler();
    }

    $register = array_filter($warnings, fn (string $message): bool => str_contains($message, 'GatewayRegistry::register() is deprecated since 0.2.0 and removed in 1.0.0'));
    $fields = array_filter($warnings, fn (string $message): bool => str_contains($message, '[legacy_once] returns array-shaped settings fields'));

    // Another test in this process may have triggered the register() warning first.
    expect(count($register))->toBeLessThanOrEqual(1)
        ->and($fields)->toHaveCount(1);
});

test('a key with a digit routes its webhook, return and admin URLs', function () {
    app(GatewayDriverRegistry::class)->register('pay2', fn () => conformanceDriver('pay2'));
    $routes = app('router')->getRoutes();

    expect(route('billing.webhooks', 'pay2', false))->toBe('/billing/webhooks/pay2')
        ->and($routes->match(Request::create('/billing/webhooks/pay2', 'POST'))->getName())->toBe('billing.webhooks')
        ->and($routes->match(Request::create('/billing/gateways/pay2/return', 'GET'))->getName())->toBe('billing.gateways.return')
        ->and($routes->match(Request::create(route('admin.billing.gateways.edit', 'pay2', false), 'GET'))->getName())->toBe('admin.billing.gateways.edit');
});

test('the kit leaves the event listeners as it found them', function () {
    $count = fn (): array => array_map('count', array_intersect_key(app('events')->getRawListeners(), array_flip([QueryExecuted::class, RequestSending::class])));
    $before = $count();

    GatewayConformance::assertDriver('stripe');
    GatewayConformance::assertDriver('khalti');

    expect($count())->toBe($before);
});

test('the deprecated register() warns exactly once, however often it is called', function () {
    (new ReflectionProperty(GatewayManager::class, 'warnedRegister'))->setValue(null, false);
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        app(GatewayRegistry::class)->register(new ManualDriver);
        app(GatewayRegistry::class)->register(new ManualDriver);
    } finally {
        restore_error_handler();
    }

    expect(array_values(array_filter($warnings, fn (string $message): bool => str_contains($message, 'GatewayRegistry::register() is deprecated since 0.2.0 and removed in 1.0.0'))))->toHaveCount(1);
});
