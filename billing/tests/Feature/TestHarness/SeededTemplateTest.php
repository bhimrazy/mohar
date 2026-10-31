<?php

// TestCase::useSeededDatabase() copies a schema migrated once per process instead of migrating each seeded database.
// The copy must be the schema a fresh migrate builds, on both databases, or a race test would run on a schema that
// production never has.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

it('copies a template that equals a fresh migrate: tables, columns, indexes, constraints, sequences, settings, extensions and migration rows', function () {
    $copy = $this->prepareSeededDatabase('probe_copy', fn () => null);
    $fresh = $this->prepareSeededDatabase('probe_fresh', fn () => null, freshMigrate: true);

    $snapshot = seededSchemaSnapshot($copy);

    expect($snapshot['tables'])->toHaveCount(count(Schema::getTableListing()))
        ->and($snapshot['tables'])->toContain('migrations')
        ->and($snapshot)->toEqual(seededSchemaSnapshot($fresh));
});

it('names the template inside the worker database\'s prefix, which scripts/pg.sh --drop matches', function () {
    $this->prepareSeededDatabase('probe_copy', fn () => null);
    $database = (string) config('database.connections.'.config('database.default').'.database');

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The template is a temporary file on SQLite.');
    }

    $template = TestCase::seededPgsqlName($database, 'template');

    expect(DB::connection('seeded_probe_copy_admin')->scalar('select count(*) from pg_database where datname = ?', [$template]))->toBe(1)
        ->and($template)->toStartWith((string) preg_replace('/_test_[0-9]+$/', '', $database).'_');
});

it('refuses a seeded database named like the template', function () {
    expect(fn () => $this->useSeededDatabase('template', fn () => null))->toThrow(LogicException::class);
});

/**
 * Everything a migration defines, per table, in a form that compares across two databases.
 *
 * @return array<string, mixed>
 */
function seededSchemaSnapshot(string $connection): array
{
    $schema = Schema::connection($connection);
    $db = DB::connection($connection);
    $tables = collect($schema->getTables())->pluck('name')->sort()->values()->all();
    $snapshot = ['tables' => $tables];

    foreach ($tables as $table) {
        $snapshot['columns'][$table] = $schema->getColumns($table);
        $snapshot['indexes'][$table] = collect($schema->getIndexes($table))->sortBy('name')->values()->all();
        $snapshot['foreign_keys'][$table] = collect($schema->getForeignKeys($table))->sortBy('name')->values()->all();
        $snapshot['rows'][$table] = $db->table($table)->count();
    }

    $snapshot['migrations'] = $db->table('migrations')->orderBy('id')->get(['migration', 'batch'])->map(fn ($row) => (array) $row)->all();

    if ($db->getDriverName() === 'pgsql') {
        $snapshot['constraints'] = $db->select("select conrelid::regclass::text as on_table, conname, contype, pg_get_constraintdef(oid) as definition
            from pg_constraint where connamespace = 'public'::regnamespace order by 1, 2");
        $snapshot['sequences'] = $db->select('select sequencename, data_type, start_value, increment_by, max_value, last_value from pg_sequences
            where schemaname = current_schema() order by 1');
        $snapshot['triggers'] = $db->select('select tgrelid::regclass::text as on_table, tgname, pg_get_triggerdef(oid) as definition
            from pg_trigger where not tgisinternal order by 1, 2');
        $snapshot['views'] = $schema->getViews();
        // CREATE DATABASE … TEMPLATE copies the files, not ALTER DATABASE … SET settings; a migration that sets one
        // would make the copy differ from a fresh migrate.
        $snapshot['database_settings'] = $db->select('select setrole, setconfig from pg_db_role_setting
            where setdatabase = (select oid from pg_database where datname = current_database()) order by setrole');
        $snapshot['extensions'] = $db->select('select extname, extversion from pg_extension order by extname');
    } else {
        // SQLite keeps every table, index, trigger and view as its CREATE statement, constraints included.
        $snapshot['objects'] = $db->select("select type, name, sql from sqlite_master where name not like 'sqlite_%' order by type, name");
        $snapshot['sequences'] = $db->select('select name, seq from sqlite_sequence order by name');
    }

    return array_map(fn ($part) => json_decode((string) json_encode($part), true), $snapshot);
}
