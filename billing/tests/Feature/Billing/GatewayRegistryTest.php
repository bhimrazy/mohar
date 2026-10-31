<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;

/**
 * `import` marks opening-balance entries in credit_ledger.gateway, so no driver may register it; keys match DriverRegistry::KEY.
 */
test('the registry refuses a reserved or malformed key and keeps the drivers it has', function (string $key, string $message) {
    $registry = app(GatewayRegistry::class);
    $before = array_keys($registry->drivers());

    expect(fn () => $registry->register(new class($key) extends ManualDriver
    {
        public function __construct(private string $driverKey) {}

        public function key(): string
        {
            return $this->driverKey;
        }
    }))->toThrow(LogicException::class, $message)
        ->and($registry->has($key))->toBeFalse()
        ->and(array_keys($registry->drivers()))->toBe($before);
})->with([
    ['import', 'The gateway key [import] is reserved'],
    ['Import', 'start with a lowercase letter'],
    [' import', 'start with a lowercase letter'],
    ['', 'start with a lowercase letter'],
    ['2checkout', 'start with a lowercase letter'],
    ['_hidden', 'start with a lowercase letter'],
]);
