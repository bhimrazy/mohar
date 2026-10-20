<?php

use App\Contracts\Migration\ImportRegistry;
use App\Contracts\Migration\RecordImporter;

/**
 * Kernel C1 (client-import spec §16, AC 1): importers run after the types they depend on.
 */
test('ordered() puts each type after its dependencies and refuses cycles and unknown dependencies', function () {
    $importer = RecordImporter::class;
    $registry = app(ImportRegistry::class);
    $registry->register('core.user', $importer, ['core.client']);
    $registry->register('core.contact', $importer, ['core.client']);
    $registry->register('core.client', $importer, ['core.client_group']);
    $registry->register('core.client_group', $importer);

    expect($registry->ordered())->toBe(['core.client_group', 'core.client', 'core.user', 'core.contact']);

    $registry->register('core.client_group', $importer, ['core.user']);
    expect(fn () => $registry->ordered())->toThrow(LogicException::class, 'Import types depend on each other in a cycle');

    $registry->register('core.client_group', $importer, ['billing.currency']);
    expect(fn () => $registry->ordered())->toThrow(LogicException::class, 'Import type [core.client_group] depends on [billing.currency], which no module registers.');
});
