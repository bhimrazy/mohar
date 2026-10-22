<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;

/**
 * `import` marks opening-balance entries in credit_ledger.gateway, so no driver may register it; keys are [a-z0-9_]{1,50}.
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
    ['Import', 'must be 1 to 50 lowercase letters'],
    [' import', 'must be 1 to 50 lowercase letters'],
    ['', 'must be 1 to 50 lowercase letters'],
]);
