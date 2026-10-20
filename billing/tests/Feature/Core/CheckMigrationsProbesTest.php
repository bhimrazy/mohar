<?php

/*
 * Review board probes for PR #111 (D3, head 729d1952): does deploy:check-migrations catch a contract step shipped
 * with the expand step? The first dataset (a dropped and a renamed column, as the brief asked) passes at 729d1952.
 * Everything below it fails at 729d1952 and reproduces a finding:
 *  - one @compatible or @contract tag anywhere in the file exempts every finding in up(), including an unrelated drop;
 *  - drops and renames through Schema::connection(), DB::connection() or a helper method that up() calls are missed,
 *    and raw SQL that changes a column's type or nullability is not checked at all;
 *  - --since=<ref> never finds a migration, because `git diff --name-only` prints paths from the repository root
 *    (billing/app/...), which the command resolves against billing/ (billing/billing/app/...).
 */

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/check-migrations-probe-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function probeMigration(string $dir, string $name, string $body, string $docblock = ''): string
{
    $path = "{$dir}/2026_10_20_000000_{$name}.php";
    file_put_contents($path, <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;

        {$docblock}
        return new class extends Migration
        {
            {$body}
        };
        PHP);

    return $path;
}

function upOnly(string $up): string
{
    return "public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n    }";
}

test('a contract step shipped with its expand step fails: dropped or renamed column', function (string $up, string $meaning) {
    $file = probeMigration($this->dir, 'expand_and_contract', upOnly($up));

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])
        ->expectsOutputToContain($meaning)
        ->assertFailed();
})->with([
    'add display_name, drop name' => [<<<'PHP'
        Schema::table('clients', function (Blueprint $table) {
            $table->string('display_name')->nullable();
        });
        DB::table('clients')->update(['display_name' => DB::raw('first_name')]);
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('first_name');
        });
        PHP, 'drops a column'],
    'rename first_name to given_name' => [<<<'PHP'
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('first_name', 'given_name');
        });
        PHP, 'renames a column'],
]);

test('a tag for one change does not exempt an unrelated drop in the same up()', function (string $docblock) {
    $file = probeMigration($this->dir, 'widen_and_drop', upOnly(<<<'PHP'
        Schema::table('currencies', fn (Blueprint $table) => $table->decimal('rate', 24, 12)->change());
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('first_name'));
        PHP), $docblock);

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])->assertFailed();
})->with([
    '@compatible' => '/** @compatible widening the rate: the previous release writes values that still fit. */',
]);

test('drops and renames that do not use the exact facade call are caught', function (string $up) {
    $file = probeMigration($this->dir, 'indirect', upOnly($up));

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])->assertFailed();
})->with([
    'Schema::connection()->dropIfExists' => ["Schema::connection('pgsql')->dropIfExists('notes');"],
    'Schema::connection()->drop' => ["Schema::connection('pgsql')->drop('notes');"],
    'DB::connection()->statement DROP' => ["DB::connection()->statement('ALTER TABLE clients DROP COLUMN first_name');"],
    'raw ALTER COLUMN TYPE' => ["DB::statement('ALTER TABLE clients ALTER COLUMN first_name TYPE varchar(20)');"],
    'raw SET NOT NULL' => ["DB::statement('ALTER TABLE clients ALTER COLUMN company SET NOT NULL');"],
]);

test('a drop in a helper method that up() calls is caught', function () {
    $file = probeMigration($this->dir, 'helper', <<<'PHP'
        public function up(): void
        {
            $this->contract();
        }

        private function contract(): void
        {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('first_name'));
        }

        public function down(): void
        {
        }
        PHP);

    $this->artisan('deploy:check-migrations', ['paths' => [$file]])->assertFailed();
});

test('--since finds a migration changed since the ref in a repository where the app is a subdirectory', function () {
    // A throwaway repository shaped like this one: the app in billing/, git at the root.
    $repo = $this->dir.'/repo';
    $app = $repo.'/billing';
    File::ensureDirectoryExists($app.'/database/migrations');
    $file = probeMigration($app.'/database/migrations', 'probe_since', upOnly("Schema::create('memos', fn (Blueprint \$table) => \$table->id());"));
    $git = fn (string ...$args) => (new Process(['git', '-c', 'user.email=probe@example.com', '-c', 'user.name=probe', ...$args], $repo))->mustRun();
    $git('init', '-q');
    $git('add', '.');
    $git('commit', '-qm', 'base');
    file_put_contents($file, str_replace("Schema::create('memos', fn (Blueprint \$table) => \$table->id());",
        "Schema::table('clients', fn (Blueprint \$table) => \$table->dropColumn('first_name'));", (string) file_get_contents($file)));

    $original = base_path();
    $this->app->setBasePath($app);

    try {
        $this->artisan('deploy:check-migrations', ['--since' => 'HEAD'])
            ->expectsOutputToContain('probe_since')
            ->assertFailed();
    } finally {
        $this->app->setBasePath($original);
    }
});
