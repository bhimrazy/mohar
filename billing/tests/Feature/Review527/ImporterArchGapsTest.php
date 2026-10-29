<?php

/**
 * Architecture review of #527 (probe): the record-importer rule should refuse each of these, which reach a provider or
 * queue a job by a route the denylist does not name. Each fails today.
 */
foreach (['HttpImporter' => 'Http facade', 'BusImporter' => 'Bus dispatcher contract', 'SyncImporter' => 'dispatch_sync()'] as $class => $route) {
    test("the record-importer rule refuses an importer that uses the {$route}", function () use ($class) {
        expect(fn () => expect('Tests\\Fixtures\\Review527\\'.$class)->not->toUse(importerForbidden()))->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);
    });
}

