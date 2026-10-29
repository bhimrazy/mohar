<?php

namespace App\Contracts\Migration;

/**
 * Capability of a RecordImporter whose fields take values that only its module knows (ADR 0012, ADR 0019): invoice
 * statuses, tax rules, registrars, extensions. Migration's mapping step lists these targets, so staff map each value
 * found in the source to one without Migration reading the owner's tables.
 *
 * A map's key names the attribute it applies to: `status` for ImportRecord::$attributes['status'], `items.tax_rule`
 * for the `tax_rule` of each child in ImportRecord::$children['items']. Migration replaces each mapped source value
 * with the chosen target's `value` before validate() and upsert() see the record, so the importer receives only its
 * own values. validate() returns an ImportIssue for a value that is not one of the listed targets: one staff have not
 * mapped yet, or a target that has since been removed.
 */
interface DeclaresValueMaps extends RecordImporter
{
    /**
     * Reads the module's reference data; writes nothing.
     *
     * @return array<string, list<array{value: string, label: string}>> the targets of each map, by map key
     */
    public function valueMaps(): array;
}
