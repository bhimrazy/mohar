<?php

/*
 * Review board probes for PR #253 (#25, head c89c1da7): security:reencrypt, APP_KEY_FILE and the install key check.
 *
 * Pass at c89c1da7 (guards): the two-process races on PostgreSQL (an app writer, and two runs at once with and without
 * a shared lock store), the checkpoint migration round trip, and the reflection-based cast coverage.
 * Fail at c89c1da7 (findings): an identity image orphaned by a prune or a crash, the import id map keyed by APP_KEY,
 * APP_KEY and APP_KEY_FILE disagreeing silently, a key file under public/, and a relative APP_KEY_FILE.
 */

use App\Modules\Core\Install\AppKeyCheck;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\EnvironmentCheck;
use App\Modules\Core\Security\EncryptedTargets;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Services\Models\Server;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

function p253Key(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function p253UseKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
}

function p253Only(string $key): Encrypter
{
    return new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
}

/**
 * Run reencrypt-worker.php processes at once; returns label => decoded JSON result.
 *
 * @param  array<string, list<string>>  $workers
 * @return array<string, array<string, mixed>>
 */
function p253Workers(array $workers, float $timeout = 60.0): array
{
    $script = __DIR__.'/Support/reencrypt-worker.php';
    $running = [];

    try {
        foreach ($workers as $label => $arguments) {
            $process = proc_open([PHP_BINARY, $script, $label, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$label] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        $deadline = microtime(true) + $timeout;

        do {
            $open = false;

            foreach ($running as &$worker) {
                $worker['out'] .= (string) stream_get_contents($worker['pipes'][1]);
                $worker['err'] .= (string) stream_get_contents($worker['pipes'][2]);
                $open = $open || ! feof($worker['pipes'][1]) || ! feof($worker['pipes'][2]);
            }
            unset($worker);

            if (microtime(true) > $deadline) {
                throw new RuntimeException('workers still running');
            }

            usleep(20_000);
        } while ($open);
    } finally {
        foreach ($running as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }

    $results = [];

    foreach ($running as $label => $worker) {
        $result = json_decode(trim($worker['out']), true);
        expect($result)->toBeArray("worker {$label}: ".$worker['out'].$worker['err'])
            ->and($result['error'] ?? null)->toBeNull("worker {$label} failed: ".($result['error'] ?? ''));
        $results[$label] = $result;
    }

    return $results;
}

describe('PR #253 probes: two processes (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    /** Seeds 200 servers whose passwords are on $old. */
    function p253SeedServers(string $old): Closure
    {
        return function () use ($old): void {
            p253UseKeys($old);
            Server::factory()->count(200)->sequence(fn ($s) => ['name' => 'p253-'.$s->index, 'hostname' => "p253-{$s->index}.example.net", 'password' => 'orig-'.$s->index])->create();
        };
    }

    test('an app process writing passwords during a run in another process loses nothing', function () {
        [$old, $new] = [p253Key(), p253Key()];
        $this->useSeededDatabase('p253_writer_'.bin2hex(random_bytes(3)), p253SeedServers($old));
        [$first, $last] = [(int) Server::query()->min('id'), (int) Server::query()->max('id')];
        $database = DB::connection()->getDatabaseName();

        $results = p253Workers([
            'run' => ['reencrypt', $database, $new, $old, 'database', '5'],
            'app' => ['writer', $database, $new, $old, (string) $first, (string) $last, '150'],
        ]);

        $current = p253Only($new);
        $written = $results['app']['written'];

        foreach (DB::table('servers')->whereBetween('id', [$first, $last])->get(['id', 'name', 'password']) as $row) {
            $expected = $written[$row->id] ?? 'orig-'.substr($row->name, 5);
            expect($current->decrypt($row->password, false))->toBe($expected, "server {$row->id}");
        }

        expect($results['run']['exit'])->toBeIn([0, 3]);
    });

    test('two runs at once: the second is refused by the database lock, and without a shared lock store nothing is lost either', function (string $store) {
        [$old, $new] = [p253Key(), p253Key()];
        $this->useSeededDatabase('p253_two_runs_'.$store.'_'.bin2hex(random_bytes(3)), p253SeedServers($old));
        [$first, $last] = [(int) Server::query()->min('id'), (int) Server::query()->max('id')];
        $database = DB::connection()->getDatabaseName();

        $results = p253Workers([
            'a' => ['reencrypt', $database, $new, $old, $store, '1'],
            'b' => ['reencrypt', $database, $new, $old, $store, '1'],
        ]);

        $current = p253Only($new);

        foreach (DB::table('servers')->whereBetween('id', [$first, $last])->get(['name', 'password']) as $row) {
            expect($current->decrypt($row->password, false))->toBe('orig-'.substr($row->name, 5));
        }

        $codes = [$results['a']['exit'], $results['b']['exit']];
        sort($codes);

        if ($store === 'database') {
            // One finished; the other was refused (exit 1, the same code as "undecryptable values") or ran after it.
            expect($codes[0])->toBe(0)
                ->and(str_contains($results['a']['output'].$results['b']['output'], 'Another security:reencrypt run is in progress') || $codes === [0, 0])->toBeTrue();
        } else {
            expect(array_diff($codes, [0, 3]))->toBe([]);
        }
    })->with(['database', 'array']);
});

describe('PR #253 probes: identity files', function () {
    beforeEach(function () {
        Storage::fake(KycDocument::DISK);
        $this->old = p253Key();
        $this->new = p253Key();
        p253UseKeys($this->old);
    });

    // PruneDocuments (and StoreDocument for a replaced document) delete the paths of a model loaded earlier. A
    // re-encryption that switches the path in between leaves the new file behind with no row: an identity image kept
    // past its retention date that nothing will ever delete.
    test('a document pruned while its file is re-encrypted leaves no identity image behind', function () {
        $document = KycDocument::factory()->create(['back_path' => null, 'superseded_at' => now()->subDays(40), 'delete_after' => now()->subDay()->toDateString()]);
        Storage::disk(KycDocument::DISK)->put($document->front_path, p253Only($this->old)->encrypt('passport-scan', false));
        $loadedByPrune = KycDocument::query()->findOrFail($document->id);

        p253UseKeys($this->new, [$this->old]);
        $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode(0);

        expect(app(DocumentVault::class)->delete($loadedByPrune))->toBeTrue()
            ->and(KycDocument::query()->find($document->id))->toBeNull()
            ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
    });

    // A crash after the new file is written and before the path switch: the resumed run writes another file and the
    // first one stays forever (also true of the old file after a crash between the switch and the delete).
    test('a run killed between writing the new file and switching the path leaves no stray identity image', function () {
        $document = KycDocument::factory()->create(['back_path' => null]);
        Storage::disk(KycDocument::DISK)->put($document->front_path, p253Only($this->old)->encrypt('passport-scan', false));
        p253UseKeys($this->new, [$this->old]);

        $crash = true;
        DB::listen(function (QueryExecuted $query) use (&$crash): void {
            if ($crash && str_starts_with($query->sql, 'update "kyc_documents"')) {
                throw new RuntimeException('killed');
            }
        });

        expect(fn () => $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->run())->toThrow(RuntimeException::class);
        $crash = false;
        $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode(0);

        expect(Storage::disk(KycDocument::DISK)->allFiles())->toBe([$document->fresh()->front_path]);
    });
});

describe('PR #253 probes: values derived from APP_KEY', function () {
    // The import id map stores "email:<HMAC keyed from APP_KEY>" so a re-run of a source never duplicates its clients
    // (migration tables, ClientMatcher). After a rotation and a clean security:reencrypt, the same email no longer
    // finds its map row: the re-import treats every email-keyed row as new to the source.
    test('an email-keyed import id map row is still found after a rotation', function () {
        [$old, $new] = [p253Key(), p253Key()];
        p253UseKeys($old);
        $source = DB::table('migration_sources')->insertGetId(['source_system' => 'csv', 'name' => 'Probe', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('migration_id_map')->insert([
            'migration_source_id' => $source, 'entity' => 'client', 'source_id' => 'email:'.MigrationRecord::emailHash('ram@example.test'),
            'target_id' => 1, 'checksum' => str_repeat('0', 64), 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        p253UseKeys($new, [$old]);
        $this->artisan('security:reencrypt')->assertExitCode(0);

        expect(DB::table('migration_id_map')->where('migration_source_id', $source)->where('source_id', 'email:'.MigrationRecord::emailHash('ram@example.test'))->exists())->toBeTrue();
    });
});

describe('PR #253 probes: cast coverage', function () {
    // EncryptedStorageCoverageTest finds casts with a regex: "encrypted" in double quotes or AsEncryptedCollection::of()
    // outside a Models directory pass it unnoticed (tried locally). This reflects over every Model class in app/ instead.
    test('every encrypted cast on any model class in app/ is a target', function () {
        $targets = array_keys(app(EncryptedTargets::class)->all());
        $casts = ['encrypted', AsEncryptedArrayObject::class, AsEncryptedCollection::class];
        $missing = [];

        foreach ((new Finder)->files()->name('*.php')->in(app_path())->notPath('#Database/Migrations#') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $model = new $class;

            foreach ($model->getCasts() as $column => $cast) {
                if (in_array(explode(':', (string) $cast, 2)[0], $casts, true) && ! in_array($model->getTable().'.'.$column, $targets, true)) {
                    $missing[] = $class.'::'.$column;
                }
            }
        }

        expect($missing)->toBe([]);
    });
});

describe('PR #253 probes: checkpoint migration', function () {
    test('the checkpoint migration goes down and up again and holds the longest names', function () {
        $migration = require app_path('Modules/Core/Database/Migrations/2026_10_22_000100_create_reencryption_checkpoints_table.php');

        $migration->down();
        expect(Schema::hasTable('reencryption_checkpoints'))->toBeFalse();
        $migration->up();

        DB::table('reencryption_checkpoints')->insert(['target' => str_repeat('t', 150), 'key_fingerprint' => str_repeat('f', 64), 'last_key' => str_repeat('k', 191), 'completed' => false, 'counts' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        expect(DB::table('reencryption_checkpoints')->count())->toBe(1);
    });
});

describe('PR #253 probes: key from a file', function () {
    function p253Check(): EnvironmentCheck
    {
        app()->detectEnvironment(fn () => 'production');

        return (new AppKeyCheck)->run();
    }

    // Rotating by updating the secret file while deploy/.env still has APP_KEY keeps the old key in use; the
    // re-encryption then finds everything "on the current key" and exits 0. The check says "Set." and nothing else.
    test('APP_KEY and APP_KEY_FILE holding different keys is flagged', function () {
        $file = tempnam(sys_get_temp_dir(), 'p253');
        file_put_contents($file, p253Key());
        config(['app.key' => p253Key(), 'app.previous_keys' => [], 'app.key_source' => 'env', 'app.key_file' => $file]);

        try {
            expect(p253Check()->status)->toBe(CheckStatus::Warning);
        } finally {
            unlink($file);
        }
    });

    // A key file under public/ is served by the web server; that is a leak, not a warning.
    test('an APP_KEY_FILE under public/ fails the check', function () {
        $file = public_path('p253-key-'.bin2hex(random_bytes(3)));
        $key = p253Key();
        file_put_contents($file, $key);
        config(['app.key' => $key, 'app.previous_keys' => [], 'app.key_source' => 'file', 'app.key_file' => $file]);

        try {
            expect(p253Check()->status)->toBe(CheckStatus::Failed);
        } finally {
            unlink($file);
        }
    });

    // A relative APP_KEY_FILE is resolved against the working directory: artisan (the app directory) and a web
    // server whose working directory is public/ read different files, or none.
    test('a relative APP_KEY_FILE gives the same key whatever the working directory', function () {
        $relative = 'storage/framework/p253-key-'.bin2hex(random_bytes(3));
        $key = p253Key();
        file_put_contents(base_path($relative), $key);
        $cwd = getcwd();
        $load = function () use ($relative): ?string {
            foreach (['APP_KEY' => '', 'APP_KEY_FILE' => $relative] as $name => $value) {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }

            try {
                return (require config_path('app.php'))['key'];
            } finally {
                unset($_ENV['APP_KEY'], $_SERVER['APP_KEY'], $_ENV['APP_KEY_FILE'], $_SERVER['APP_KEY_FILE']);
            }
        };

        try {
            chdir(base_path());
            $fromBase = $load();
            chdir(public_path());
            $fromPublic = $load();
        } finally {
            chdir((string) $cwd);
            unlink(base_path($relative));
        }

        expect($fromBase)->toBe($key)->and($fromPublic)->toBe($key);
    });
});
