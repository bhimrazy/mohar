<?php

namespace App\Data\Migration;

/**
 * One source record in our vocabulary (our field names, enums, minor units and ISO dates). $entity is the importer's
 * registry key ("client", "invoice", "domain"); $sourceSystem the kind of source ("csv", "whmcs_api").
 *
 * Records written together travel as one record with $children: an invoice carries `items` and `payments`. The
 * parent's importer writes its children, and its revert() takes them back. `invoice_item` and `payment` are reserved
 * entity names that no importer registers; how children's outcomes are reported is decided with
 * invoice-domain-import PR 2.
 */
final readonly class ImportRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int|null>  $links  target ids of related records, e.g. ['client_id' => 42]
     * @param  array<string, list<array<string, mixed>>>  $children  e.g. ['items' => [...], 'payments' => [...]]
     * @param  list<array{path: string, name: string, mime: string, size: int, sha256: string}>  $files  staged on the objects disk
     */
    public function __construct(
        public string $entity,
        public string $sourceSystem,
        public string $sourceId,
        public array $attributes,
        public array $links = [],
        public array $children = [],
        public array $files = [],
        public string $checksum = '',
    ) {}
}
