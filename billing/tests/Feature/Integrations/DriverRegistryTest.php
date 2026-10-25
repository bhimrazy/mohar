<?php

use App\Contracts\Integrations\IntegrationDriver;
use App\Contracts\Integrations\ProvidesLogo;
use App\Data\Integrations\IntegrationLogo;
use App\Support\Integrations\DriverRegistry;
use Illuminate\Support\Facades\Log;

/**
 * @return DriverRegistry<IntegrationDriver>
 */
function exampleRegistry(): DriverRegistry
{
    return new class(app()) extends DriverRegistry
    {
        protected function contract(): string
        {
            return IntegrationDriver::class;
        }

        public function capabilities(): array
        {
            return ['logo' => ProvidesLogo::class];
        }
    };
}

function exampleDriver(string $key, bool $withLogo = false): IntegrationDriver
{
    return $withLogo
        ? new class($key) implements IntegrationDriver, ProvidesLogo
        {
            public function __construct(private string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function name(): string
            {
                return 'Example';
            }

            public function logo(): IntegrationLogo
            {
                return new IntegrationLogo('/images/example.svg', 'Example');
            }
        }
    : new class($key) implements IntegrationDriver
    {
        public function __construct(private string $key) {}

        public function key(): string
        {
            return $this->key;
        }

        public function name(): string
        {
            return 'Example';
        }
    };
}

test('registration is lazy and drivers resolve through the container on demand', function () {
    $registry = exampleRegistry();
    $resolved = 0;
    $registry->register('example_one', function () use (&$resolved): IntegrationDriver {
        $resolved++;

        return exampleDriver('example_one');
    });

    expect($resolved)->toBe(0)
        ->and($registry->isRegistered('example_one'))->toBeTrue()
        ->and($resolved)->toBe(0)
        ->and($registry->get('example_one')->key())->toBe('example_one')
        ->and($resolved)->toBe(1)
        // keys() and has() check a driver once (#490); after it passes they list it without resolving again.
        ->and($registry->keys())->toBe(['example_one'])
        ->and($registry->keys())->toBe(['example_one'])
        ->and($registry->has('example_one'))->toBeTrue()
        ->and($resolved)->toBe(2);
});

test('capabilities are computed from the interfaces a driver implements', function () {
    $registry = exampleRegistry();
    $registry->register('plain', fn () => exampleDriver('plain'));
    $registry->register('branded', fn () => exampleDriver('branded', withLogo: true));

    expect($registry->capabilitiesOf('plain'))->toBe([])
        ->and($registry->capabilitiesOf('branded'))->toBe(['logo'])
        ->and(array_keys($registry->all()))->toBe(['plain', 'branded']);
});

test('a duplicate key is refused unless it replaces the driver, and a replacement is logged', function () {
    Log::spy();
    $registry = exampleRegistry();
    $registry->register('example', fn () => exampleDriver('example'));

    expect(fn () => $registry->register('example', fn () => exampleDriver('example')))->toThrow(LogicException::class, 'already registered');

    $replacement = exampleDriver('example', withLogo: true);
    $registry->register('example', fn () => $replacement, replace: true);

    expect($registry->get('example'))->toBe($replacement);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['key'] === 'example')->once();
});

test('invalid keys, unknown keys, key mismatches and drivers of the wrong type are refused', function () {
    $registry = exampleRegistry();

    expect(fn () => $registry->register('Acme-Panel', fn () => exampleDriver('x')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(str_repeat('a', 51), fn () => exampleDriver('x')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('missing'))->toThrow(InvalidArgumentException::class, 'No driver is registered as [missing]');

    $registry->register('mismatch', fn () => exampleDriver('other'));
    $registry->register('wrong_type', fn () => new stdClass);

    expect(fn () => $registry->get('mismatch'))->toThrow(LogicException::class, 'reports the key [other]')
        ->and(fn () => $registry->get('wrong_type'))->toThrow(LogicException::class, 'does not implement');
});
