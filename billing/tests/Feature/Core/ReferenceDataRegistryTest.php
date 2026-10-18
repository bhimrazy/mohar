<?php

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Support\Install\ReferenceDataRegistry;

function referenceInstaller(string $key): InstallsReferenceData
{
    return new class($key) implements InstallsReferenceData
    {
        public function __construct(private string $key) {}

        public function key(): string
        {
            return $this->key;
        }

        public function label(): string
        {
            return $this->key;
        }

        public function install(): ReferenceDataResult
        {
            return new ReferenceDataResult;
        }
    };
}

test('installers run by order, then in registration order, with Core first', function () {
    $registry = app(ReferenceDataRegistry::class);
    $registry->register('test_late', fn () => referenceInstaller('test_late'), order: 200);
    $registry->register('test_default_a', fn () => referenceInstaller('test_default_a'));
    $registry->register('test_default_b', fn () => referenceInstaller('test_default_b'));

    expect(array_slice($registry->keys(), 0, 2))->toBe(['core_currencies', 'core_roles'])
        ->and(array_values(array_filter($registry->keys(), fn (string $key) => str_starts_with($key, 'test_'))))->toBe(['test_default_a', 'test_default_b', 'test_late']);
});

test('a duplicate key is refused unless it replaces the installer', function () {
    $registry = app(ReferenceDataRegistry::class);

    expect(fn () => $registry->register('core_roles', fn () => referenceInstaller('core_roles')))->toThrow(LogicException::class, 'already registered');

    $replacement = referenceInstaller('core_roles');
    $registry->register('core_roles', fn () => $replacement, replace: true);

    expect($registry->get('core_roles'))->toBe($replacement);
});

test('an invalid key, an installer reporting another key, or a registration of the wrong type is refused', function () {
    $registry = app(ReferenceDataRegistry::class);

    expect(fn () => $registry->register('Notifications.Templates', fn () => referenceInstaller('x')))->toThrow(InvalidArgumentException::class);

    $registry->register('test_mismatch', fn () => referenceInstaller('test_other'));

    expect(fn () => $registry->get('test_mismatch'))->toThrow(LogicException::class, 'reports the key [test_other]');

    $registry->register('test_wrong_type', fn () => new stdClass);

    expect(fn () => $registry->get('test_wrong_type'))->toThrow(LogicException::class, 'resolves to stdClass, not an '.InstallsReferenceData::class);
});
