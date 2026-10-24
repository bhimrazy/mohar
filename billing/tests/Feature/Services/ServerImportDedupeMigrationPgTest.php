<?php

use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use Illuminate\Support\Facades\DB;

/**
 * Migration 2026_10_24_400750 on PostgreSQL (#453 follow-up). The SQLite test of the same migration cannot run there:
 * the unique index is built CONCURRENTLY, which PostgreSQL refuses inside the test's transaction. This one runs the
 * migration on its own committed session against a seeded database, with duplicate report lines in place.
 */
test('the report-line migration dedupes duplicated lines, recounts the import and builds a valid unique index', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: the index is built concurrently, outside any transaction.');
    }

    $this->useSeededDatabase('report_line_dedupe', fn () => null);
    $seeded = DB::connection()->getName();
    config(["database.connections.{$seeded}_run" => config("database.connections.{$seeded}")]);
    $run = DB::connection("{$seeded}_run");
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection("{$seeded}_run");

    try {
        $server = Server::factory()->create();
        $import = ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'account_count' => 2, 'imported_count' => 3, 'not_imported_count' => 2, 'expires_at' => now()->addDay()]);
        $other = ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'account_count' => 1, 'imported_count' => 1, 'not_imported_count' => 0, 'expires_at' => now()->addDay()]);
        $run->statement('drop index sair_lines_import_username_unique');
        $line = fn (int $importId, string $username, bool $imported): array => ['server_account_import_id' => $importId, 'username' => $username, 'imported' => $imported, 'message' => 'm', 'created_at' => now()];
        $run->table('server_account_import_report_lines')->insert([
            $line($import->id, 'alpha', true), $line($import->id, 'bravo', false), $line($import->id, 'alpha', false), $line($import->id, 'alpha', true), $line($import->id, 'bravo', false),
            $line($other->id, 'alpha', true),
            ...array_map(fn (int $number): array => $line($import->id, "filler{$number}", true), range(1, 3000)),
            ...array_map(fn (int $number): array => $line($import->id, "filler{$number}", false), range(1, 1000)),
        ]);

        $migration = require base_path('app/Modules/Services/Database/Migrations/2026_10_24_400750_add_outcome_and_unique_to_server_account_import_report_lines.php');
        $migration->up();

        $kept = $run->table('server_account_import_report_lines')->where('server_account_import_id', $import->id);
        $index = $run->selectOne("select i.indisvalid as valid, i.indisunique as is_unique from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'sair_lines_import_username_unique'");

        expect((clone $kept)->count())->toBe(3002)
            ->and((clone $kept)->distinct()->count('username'))->toBe(3002)
            ->and((clone $kept)->whereIn('username', ['alpha', 'bravo'])->orderBy('username')->pluck('imported', 'username')->map(fn ($imported) => (bool) $imported)->all())->toBe(['alpha' => true, 'bravo' => false])
            ->and($import->refresh()->only(['imported_count', 'not_imported_count']))->toBe(['imported_count' => 3001, 'not_imported_count' => 1])
            ->and($other->refresh()->only(['imported_count', 'not_imported_count']))->toBe(['imported_count' => 1, 'not_imported_count' => 0])
            ->and([$index->valid, $index->is_unique])->toBe([true, true]);

        $migration->down();
        $migration->up();

        expect($run->selectOne("select i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'sair_lines_import_username_unique'")->valid)->toBeTrue();
    } finally {
        DB::setDefaultConnection($default);
        $run->table('servers')->delete();
        $run->disconnect();
    }
});
