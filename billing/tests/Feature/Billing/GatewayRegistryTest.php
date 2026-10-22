<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;

/**
 * `import` marks pre-#263 opening-balance entries in credit_ledger.gateway, so no driver may register it.
 */
test('the registry refuses a driver with a reserved key and keeps the drivers it has', function () {
    $registry = app(GatewayRegistry::class);
    $before = array_keys($registry->drivers());

    expect(fn () => $registry->register(new class extends ManualDriver
    {
        public function key(): string
        {
            return 'import';
        }
    }))->toThrow(LogicException::class, 'The gateway key [import] is reserved')
        ->and($registry->has('import'))->toBeFalse()
        ->and(array_keys($registry->drivers()))->toBe($before);
});
