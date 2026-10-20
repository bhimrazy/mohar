<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/check-migrations-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * Write a migration whose up() and down() bodies are the given statements.
 */
function migrationFile(string $dir, string $name, string $up, string $down = '', string $docblock = ''): string
{
    $path = "{$dir}/2026_10_19_000000_{$name}.php";
    file_put_contents($path, <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;

        {$docblock}
        return new class extends Migration
        {
            public function up(): void
            {
                {$up}
            }

            public function down(): void
            {
                {$down}
            }
        };
        PHP);

    return $path;
}

test('an additive migration passes, even when its down() drops what it added', function () {
    $file = migrationFile($this->dir, 'add_notes', <<<'PHP'
        Schema::create('notes', fn (Blueprint $table) => $table->id());
        Schema::table('clients', function (Blueprint $table) {
            $table->string('nickname')->nullable();
            $table->index('nickname');
        });
        PHP, "Schema::dropIfExists('notes');");

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])
        ->expectsOutputToContain('expand only')
        ->assertSuccessful();
});

test('dropping, renaming or changing a column or table in up() fails', function (string $up, string $meaning) {
    $file = migrationFile($this->dir, 'destructive', $up);

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])
        ->expectsOutputToContain($meaning)
        ->assertFailed();
})->with([
    'drop column' => ["Schema::table('clients', fn (Blueprint \$table) => \$table->dropColumn('nickname'));", 'drops a column'],
    'drop soft deletes' => ["Schema::table('clients', fn (Blueprint \$table) => \$table->dropSoftDeletes());", 'drops a column'],
    'rename column' => ["Schema::table('clients', fn (Blueprint \$table) => \$table->renameColumn('a', 'b'));", 'renames a column'],
    'change column' => ["Schema::table('clients', fn (Blueprint \$table) => \$table->string('a', 20)->change());", 'changes a column definition'],
    'drop table' => ["Schema::dropIfExists('notes');", 'drops a table or columns'],
    'rename table' => ["Schema::rename('notes', 'memos');", 'renames a table'],
    'raw SQL' => ["DB::statement('ALTER TABLE clients DROP COLUMN nickname');", 'runs raw SQL that drops or renames'],
]);

test('a migration tagged @contract is reported as the contract step, not failed', function () {
    $file = migrationFile($this->dir, 'contract_nickname', "Schema::table('clients', fn (Blueprint \$table) => \$table->dropColumn('nickname'));",
        docblock: '/** @contract nickname has not been read since release 1.4 (expanded in 1.3). */');

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])
        ->expectsOutputToContain('contract step (@contract): drops a column')
        ->assertSuccessful();
});

test('a change tagged @compatible is reported as compatible, not failed', function () {
    $file = migrationFile($this->dir, 'widen_rate', "Schema::table('currencies', fn (Blueprint \$table) => \$table->decimal('rate', 24, 12)->change());",
        docblock: '/** @compatible widening: the previous release writes values that still fit. */');

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])
        ->expectsOutputToContain('compatible change (@compatible): changes a column definition')
        ->assertSuccessful();
});

test('a comment mentioning a drop does not count', function () {
    $file = migrationFile($this->dir, 'commented', "// Later: \$table->dropColumn('nickname');\nSchema::create('memos', fn (Blueprint \$table) => \$table->id());");

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])->assertSuccessful();
});

test('--pending checks only migrations the database has not run, so a migrated database has none', function () {
    $this->artisan('deploy:check-migrations', ['--pending' => true])
        ->expectsOutputToContain('No migrations to check.')
        ->assertSuccessful();
});

test('without files, --pending or --since it says what to pass', function () {
    $this->artisan('deploy:check-migrations')
        ->expectsOutputToContain('Pass migration files, --pending or --since=<git ref>.')
        ->assertFailed();
});

test('a path that does not exist fails instead of passing as an empty migration', function () {
    $this->artisan('deploy:check-migrations', ['paths' => [$this->dir.'/2026_10_20_000000_missing.php']])
        ->expectsOutputToContain('is not a readable file')
        ->assertFailed();
});
