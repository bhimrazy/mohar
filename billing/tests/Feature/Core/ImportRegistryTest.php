<?php

use App\Contracts\Migration\RecordImporter;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Support\Migration\ImportRegistry;

/**
 * Kernel C1 (client-import spec §16, AC 1; ADR 0012): importers are registered once by entity key, report that key,
 * and run after the entities they depend on. Invoice-domain import spec AC 2: `after:` orders without requiring.
 */
function fakeImporter(string $key): Closure
{
    return fn (): RecordImporter => new class($key) implements RecordImporter
    {
        public function __construct(private string $entity) {}

        public function key(): string
        {
            return $this->entity;
        }

        public function rules(): array
        {
            return [];
        }

        public function validate(ImportRecord $record, ImportContext $context): array
        {
            return [];
        }

        public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
        {
            return new ImportOutcome(null, ImportAction::Skipped);
        }

        public function finalize(ImportContext $context): array
        {
            return [];
        }

        public function cutover(ImportContext $context): int
        {
            return 0;
        }
    };
}

test('ordered() puts each entity after its dependencies and checks only the chains asked for', function () {
    $registry = new ImportRegistry(app());
    $registry->register('user', fakeImporter('user'), dependsOn: ['client']);
    $registry->register('contact', fakeImporter('contact'), dependsOn: ['client']);
    $registry->register('client', fakeImporter('client'), dependsOn: ['client_group']);
    $registry->register('client_group', fakeImporter('client_group'));
    $registry->register('invoice', fakeImporter('invoice'), dependsOn: ['client', 'service']);

    expect($registry->ordered(['user', 'contact']))->toBe(['client_group', 'client', 'user', 'contact'])
        ->and($registry->keys())->toBe(['user', 'contact', 'client', 'client_group', 'invoice'])
        ->and(fn () => $registry->ordered())->toThrow(LogicException::class, 'Import entity [invoice] depends on [service], which no module registers.');

    $registry->register('client_group', fakeImporter('client_group'), replace: true, dependsOn: ['user']);
    expect(fn () => $registry->ordered(['client']))->toThrow(LogicException::class, 'Import entities depend on each other in a cycle');
});

test('keys are validated, a duplicate needs replace, and an importer must report its key', function () {
    $registry = new ImportRegistry(app());
    $registry->register('client', fakeImporter('client'));
    $registry->register('contact', fakeImporter('client'));

    expect(fn () => $registry->register('core.client', fakeImporter('client')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('client', fakeImporter('client')))->toThrow(LogicException::class, 'pass replace: true')
        ->and($registry->get('client')->key())->toBe('client')
        ->and(fn () => $registry->get('contact'))->toThrow(LogicException::class, 'reports the key [client]');

    $registry->register('client', fakeImporter('client'), replace: true);
    expect($registry->has('client'))->toBeTrue();
});

test('after: orders an entity after those in the same import and ignores those that are absent', function () {
    $registry = new ImportRegistry(app());
    $registry->register('invoice', fakeImporter('invoice'), dependsOn: ['client'], after: ['domain', 'service']);
    $registry->register('client', fakeImporter('client'));

    // No domain or service importer (Domains filtered out, services not built): the invoice import still runs.
    expect($registry->ordered(['invoice']))->toBe(['client', 'invoice'])
        ->and($registry->ordered())->toBe(['client', 'invoice']);

    $registry->register('domain', fakeImporter('domain'), dependsOn: ['client']);

    // A registered after: entity is ordered first only when it is part of the import; it is never added.
    expect($registry->ordered())->toBe(['client', 'domain', 'invoice'])
        ->and($registry->ordered(['invoice', 'domain']))->toBe(['client', 'domain', 'invoice'])
        ->and($registry->ordered(['invoice']))->toBe(['client', 'invoice'])
        ->and(fn () => $registry->register('payment', fakeImporter('payment'), after: ['Domain']))->toThrow(InvalidArgumentException::class);

    $registry->register('domain', fakeImporter('domain'), replace: true, after: ['invoice']);
    expect(fn () => $registry->ordered())->toThrow(LogicException::class, 'Import entities depend on each other in a cycle: invoice → domain → invoice.');
});
