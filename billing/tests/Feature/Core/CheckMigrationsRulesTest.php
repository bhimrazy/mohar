<?php

/*
 * deploy:check-migrations, architecture rules 5 and 6, per-statement tags, tightened keys, duplicate timestamps (P11).
 */

use App\Modules\Core\Support\MigrationLint;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/check-migrations-rules-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function ruleMigration(string $dir, string $name, string $methods, string $props = '', string $timestamp = '2026_10_19_000001'): string
{
    $path = "{$dir}/{$timestamp}_{$name}.php";
    file_put_contents($path, <<<PHP
        <?php

        use App\\Modules\\Core\\Support\\LockTimeout;
        use App\\Modules\\Core\\Support\\OnlineIndex;
        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            {$props}

            {$methods}

            public function down(): void
            {
                Schema::dropIfExists('anything');
            }
        };
        PHP);

    return $path;
}

function ruleUp(string $up): string
{
    return "public function up(): void\n    {\n        {$up}\n    }";
}

test('rule 5: DDL on an existing table outside LockTimeout::run fails, inside it passes', function (string $up, bool $passes) {
    $file = ruleMigration($this->dir, 'lock', ruleUp($up));
    $run = $this->artisan('deploy:check-migrations', ['paths' => [$file]]);

    $passes ? $run->assertSuccessful() : $run->expectsOutputToContain('outside LockTimeout::run')->assertFailed();
})->with([
    'bare Schema::table' => ["Schema::table('clients', fn (Blueprint \$table) => \$table->string('a')->nullable());", false],
    'raw ALTER TABLE' => ["DB::statement('ALTER TABLE clients ADD COLUMN a varchar(10)');", false],
    'through Schema::connection' => ["Schema::connection('pgsql')->table('clients', fn (Blueprint \$table) => \$table->string('a')->nullable());", false],
    'wrapped' => ["LockTimeout::run('clients', fn () => Schema::table('clients', fn (Blueprint \$table) => \$table->string('a')->nullable()));", true],
    'a table created in the same migration' => ["Schema::create('memos', fn (Blueprint \$table) => \$table->id());\n        Schema::table('memos', fn (Blueprint \$table) => \$table->string('a')->nullable());", true],
]);

test('rule 5: a helper that only runs inside LockTimeout::run is covered, one called outside is not', function () {
    $helper = "private function alter(): void\n    {\n        Schema::table('clients', fn (Blueprint \$table) => \$table->string('a')->nullable());\n    }";

    $covered = ruleMigration($this->dir, 'covered', ruleUp("LockTimeout::run('clients', fn () => \$this->alter());")."\n\n".$helper);
    $this->artisan('deploy:check-migrations', ['paths' => [$covered]])->assertSuccessful();

    $bare = ruleMigration($this->dir, 'bare', ruleUp('$this->alter();')."\n\n".$helper, timestamp: '2026_10_19_000002');
    $this->artisan('deploy:check-migrations', ['paths' => [$bare]])->expectsOutputToContain('outside LockTimeout::run')->assertFailed();
});

test('rule 6: an index outside OnlineIndex fails; on a new table it is fine', function (string $up, bool $passes) {
    $file = ruleMigration($this->dir, 'index', ruleUp($up));
    $run = $this->artisan('deploy:check-migrations', ['paths' => [$file]]);

    $passes ? $run->assertSuccessful() : $run->expectsOutputToContain('outside OnlineIndex')->assertFailed();
})->with([
    'index() in Schema::table' => ["LockTimeout::run('clients', fn () => Schema::table('clients', fn (Blueprint \$table) => \$table->index('email')));", false],
    'raw CREATE INDEX' => ["DB::statement('CREATE INDEX clients_a_index ON clients (a)');", false],
    'index() on a new table' => ["Schema::create('memos', function (Blueprint \$table) {\n            \$table->id();\n            \$table->index('id');\n        });", true],
]);

test('rule 6: OnlineIndex::create needs withinTransaction = false', function () {
    $up = ruleUp("OnlineIndex::create('clients', ['email']);");

    $this->artisan('deploy:check-migrations', ['paths' => [ruleMigration($this->dir, 'online_in_tx', $up)]])
        ->expectsOutputToContain('withinTransaction')
        ->assertFailed();

    $this->artisan('deploy:check-migrations', ['paths' => [ruleMigration($this->dir, 'online', $up, 'public $withinTransaction = false;', '2026_10_19_000002')]])
        ->assertSuccessful();
});

test('a unique index or constraint on an existing table needs a tag', function (string $up) {
    $file = ruleMigration($this->dir, 'unique', ruleUp($up), 'public $withinTransaction = false;');

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])->expectsOutputToContain('the previous release may violate')->assertFailed();
})->with([
    'OnlineIndex unique' => ["OnlineIndex::create('clients', ['email'], unique: true);"],
    'raw ADD CONSTRAINT CHECK' => ["LockTimeout::run('clients', fn () => DB::statement('ALTER TABLE clients ADD CONSTRAINT c CHECK (a > 0)'));"],
]);

test('a tag excuses the statement it precedes, and only that one', function () {
    $up = ruleUp(<<<'PHP'
        // @compatible widening: the previous release writes values that still fit.
        LockTimeout::run('currencies', fn () => Schema::table('currencies', fn (Blueprint $table) => $table->decimal('rate', 24, 12)->change()));
        LockTimeout::run('clients', fn () => Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('first_name')));
        PHP);

    $this->artisan('deploy:check-migrations', ['paths' => [ruleMigration($this->dir, 'two', $up)]])
        ->expectsOutputToContain('compatible change (@compatible): changes a column definition')
        ->expectsOutputToContain('drops a column')
        ->assertFailed();

    $both = str_replace("LockTimeout::run('clients'", "// @contract first_name unused since release 1.2.\n        LockTimeout::run('clients'", $up);

    $this->artisan('deploy:check-migrations', ['paths' => [ruleMigration($this->dir, 'tagged_both', $both, timestamp: '2026_10_19_000002')]])
        ->assertSuccessful();
});

test('a tag without a reason excuses nothing', function () {
    $up = ruleUp("// @contract\n        LockTimeout::run('clients', fn () => Schema::table('clients', fn (Blueprint \$table) => \$table->dropColumn('a')));");

    $this->artisan('deploy:check-migrations', ['paths' => [ruleMigration($this->dir, 'bare_tag', $up)]])->assertFailed();
});

test('a foreign key dropped and added again stricter needs a tag; a looser one or a plain addition does not', function (string $up, bool $passes) {
    $file = ruleMigration($this->dir, 'fk', ruleUp($up));
    $run = $this->artisan('deploy:check-migrations', ['paths' => [$file]]);

    $passes ? $run->assertSuccessful() : $run->expectsOutputToContain('tightens a foreign key')->assertFailed();
})->with([
    'restrictOnDelete' => ["LockTimeout::run('notes', fn () => Schema::table('notes', function (Blueprint \$table) {\n            \$table->dropForeign(['created_by']);\n            \$table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();\n        }));", false],
    'no action named' => ["LockTimeout::run('notes', fn () => Schema::table('notes', function (Blueprint \$table) {\n            \$table->dropForeign(['created_by']);\n            \$table->foreign('created_by')->references('id')->on('users');\n        }));", false],
    'nullOnDelete' => ["LockTimeout::run('notes', fn () => Schema::table('notes', function (Blueprint \$table) {\n            \$table->dropForeign(['created_by']);\n            \$table->foreign('created_by')->references('id')->on('users')->nullOnDelete();\n        }));", true],
    'a new key only' => ["LockTimeout::run('notes', fn () => Schema::table('notes', fn (Blueprint \$table) => \$table->foreign('created_by')->references('id')->on('users')->restrictOnDelete()));", true],
]);

test('a duplicate timestamp among the checked files fails', function () {
    $a = ruleMigration($this->dir, 'first', ruleUp("Schema::create('a', fn (Blueprint \$table) => \$table->id());"));
    $b = ruleMigration($this->dir, 'second', ruleUp("Schema::create('b', fn (Blueprint \$table) => \$table->id());"));

    $this->artisan('deploy:check-migrations', ['paths' => [$a, $b]])
        ->expectsOutputToContain('2026_10_19_000001 is used by')
        ->assertFailed();
});

test('a new migration may not reuse the timestamp of a merged one, except the allow-listed pairs', function () {
    $merged = collect(app('migrator')->getMigrationFiles(array_merge([database_path('migrations')], app('migrator')->paths())))->values()->all();
    $taken = basename($merged[0], '.php');
    preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $taken, $prefix);

    $file = ruleMigration($this->dir, 'reuse', ruleUp("Schema::create('a', fn (Blueprint \$table) => \$table->id());"), timestamp: $prefix[1]);

    expect(MigrationLint::duplicateTimestamps([$file], $merged))->toHaveCount(1);
    expect(MigrationLint::duplicateTimestamps($merged, $merged))->toBe([]);
});

test('the repository has no duplicate timestamps beyond the allow-list', function () {
    $merged = array_values(app('migrator')->getMigrationFiles(array_merge([database_path('migrations')], app('migrator')->paths())));
    $names = array_map(fn (string $path): string => basename($path, '.php'), $merged);

    $groups = [];

    foreach ($names as $name) {
        $groups[substr($name, 0, 17)][] = $name;
    }

    $shared = array_filter($groups, fn (array $group): bool => count($group) > 1);
    ksort($shared);
    $allowed = MigrationLint::ALLOWED_DUPLICATE_TIMESTAMPS;
    ksort($allowed);

    expect(array_map(fn (array $g): array => (function () use ($g) {
        sort($g);

        return $g;
    })(), $shared))->toBe($allowed);
});

test('the baseline lists only migrations that exist, and every other migration passes the checker', function () {
    $migrator = app('migrator');
    $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
    $baseline = array_values(array_filter(
        array_map('trim', file(database_path('migration-rules-baseline.txt'), FILE_IGNORE_NEW_LINES)),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));

    expect(array_diff($baseline, array_keys($files)))->toBe([]);

    $new = array_values(array_diff_key($files, array_flip($baseline)));

    foreach ($new as $path) {
        $errors = array_filter(MigrationLint::analyse((string) file_get_contents($path)), fn (array $f): bool => $f['level'] === 'error');

        expect($errors)->toBe([], basename($path).' breaks the migration rules; run php artisan deploy:check-migrations '.basename($path));
    }
});
