<?php

/*
 * Re-check probes for PR #253 at bcb189b0: the rotation-proof import email hash (InstallSecrets) and the pending-file
 * protocol of security:reencrypt for identity images.
 *
 * Expected at bcb189b0:
 * - pass: a fresh install that imports, rotates twice and re-imports the same file gets no new id-map rows; every
 *   crash window and concurrent DocumentVault::delete that leaves the row or the files consistent.
 * - fail (findings): an install upgraded from an earlier version whose secret is first created after the old key is
 *   retired or after a second rotation; a run killed after the path switch whose document is then deleted.
 */

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\ReencryptionCheckpoint;
use App\Modules\Core\Security\EncryptedTargets;
use App\Modules\Core\Security\Reencryptor;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Services\Models\Server;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

function r253Key(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/** Switch keys as a restart does: a new encrypter and a new process (no memoized install secret). */
function r253Restart(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
    app()->forgetInstance(InstallSecrets::class);
}

function r253Only(string $key): Encrypter
{
    return new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
}

describe('PR #253 re-check: import email hash across rotations', function () {
    beforeEach(function () {
        Storage::fake('objects');
        Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
        actingAsStaff();
        confirmPassword();
    });

    // A file without an External ID column: the id map is keyed "email:<hash>".
    function r253File(): UploadedFile
    {
        $header = ['First Name', 'Last Name', 'Email Address', 'Country', 'Status'];

        return ClientCsv::file([['Asha', 'Rai', 'asha@example.test', 'NP', 'Active'], ['Ram', 'Thapa', 'ram@example.test', 'NP', 'Active']], $header);
    }

    /** Import the file, then re-import it into the same source; returns the re-run. */
    function r253ImportTwice(Closure $between): MigrationRun
    {
        $first = ClientCsv::check(ClientCsv::upload(r253File()));
        $source = ClientCsv::apply($first)->migration_source_id;
        expect(Client::query()->count())->toBe(2)->and(MigrationIdMap::query()->count())->toBe(2);

        $between();

        return ClientCsv::check(ClientCsv::upload(r253File(), ['source_id' => $source]));
    }

    function r253Reencrypt(): void
    {
        test()->artisan('security:reencrypt')->assertExitCode(0);
    }

    // The brief's scenario on a fresh install: the secret is created at the first import check.
    test('fresh install: import, rotate twice (runbook order), retire, re-import the same file: nothing new', function () {
        [$k1, $k2, $k3] = [r253Key(), r253Key(), r253Key()];
        r253Restart($k1);

        $rerun = r253ImportTwice(function () use ($k1, $k2, $k3): void {
            r253Restart($k2, [$k1]);
            r253Reencrypt();
            r253Restart($k3, [$k2, $k1]);
            r253Reencrypt();
            r253Restart($k3);
        });

        expect($rerun->create_count)->toBe(0)->and($rerun->unchanged_count)->toBe(2)
            ->and(MigrationIdMap::query()->count())->toBe(2);
    });

    // Upgraded install: the import ran on a version before install_secrets, so its hashes are keyed from K1 and no
    // secret row exists (the initial value equals that old key, so deleting the row reproduces the upgrade exactly).
    // The operator follows the runbook: rotate, re-encrypt (exit 0), wait, remove K1. The first import check after
    // that creates the secret from K2.
    test('upgraded install: rotate, re-encrypt and retire the old key before any import check: the re-import still matches', function () {
        [$k1, $k2] = [r253Key(), r253Key()];
        r253Restart($k1);

        $rerun = r253ImportTwice(function () use ($k1, $k2): void {
            DB::table('install_secrets')->delete();
            r253Restart($k2, [$k1]);
            r253Reencrypt();
            r253Restart($k2);
        });

        expect($rerun->unchanged_count)->toBe(2)->and($rerun->create_count)->toBe(0)
            ->and(MigrationIdMap::query()->count())->toBe(2);
    });

    // Same upgrade, two rotations, every old key kept "old key first" (runbook step 3): previous_keys[0] is K2, not
    // the K1 the stored hashes were made with.
    test('upgraded install: two rotations with every old key kept: the re-import still matches', function () {
        [$k1, $k2, $k3] = [r253Key(), r253Key(), r253Key()];
        r253Restart($k1);

        $rerun = r253ImportTwice(function () use ($k1, $k2, $k3): void {
            DB::table('install_secrets')->delete();
            r253Restart($k2, [$k1]);
            r253Reencrypt();
            r253Restart($k3, [$k2, $k1]);
            r253Reencrypt();
        });

        expect($rerun->unchanged_count)->toBe(2)->and($rerun->create_count)->toBe(0)
            ->and(MigrationIdMap::query()->count())->toBe(2);
    });
});

describe('PR #253 re-check: identity image crash windows and concurrent deletes', function () {
    beforeEach(function () {
        Storage::fake(KycDocument::DISK);
        $this->old = r253Key();
        $this->new = r253Key();
        r253Restart($this->old);
        $this->document = KycDocument::factory()->create(['back_path' => null]);
        Storage::disk(KycDocument::DISK)->put($this->document->front_path, r253Only($this->old)->encrypt('passport-scan', false));
        r253Restart($this->new, [$this->old]);
    });

    function r253Files(): array
    {
        return Storage::disk(KycDocument::DISK)->allFiles();
    }

    function r253Pending(KycDocument $document, string $new): void
    {
        ReencryptionCheckpoint::query()->create(['target' => 'kyc_documents.files', 'key_fingerprint' => 'x', 'counts' => [],
            'pending' => ['key' => $document->id, 'column' => 'front_path', 'old' => $document->front_path, 'new' => $new]]);
    }

    function r253Run(int $exit = 0): void
    {
        test()->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode($exit);
    }

    // Window 1: killed after recording pending, before writing the new file; the document is pruned meanwhile.
    test('killed before the write, document deleted before the next run: no file left', function () {
        $new = dirname($this->document->front_path).'/never-written';
        r253Pending($this->document, $new);
        expect(app(DocumentVault::class)->delete($this->document->fresh()))->toBeTrue();

        r253Run();

        expect(r253Files())->toBe([]);
    });

    // Window 2: killed after the write, before the switch; the document is pruned meanwhile.
    test('killed after the write and before the switch, document deleted before the next run: no file left', function () {
        $new = dirname($this->document->front_path).'/written-not-switched';
        r253Pending($this->document, $new);
        Storage::disk(KycDocument::DISK)->put($new, r253Only($this->new)->encrypt('passport-scan', false));
        expect(app(DocumentVault::class)->delete($this->document->fresh()))->toBeTrue();

        r253Run();

        expect(r253Files())->toBe([]);
    });

    // Window 3: killed after the switch committed, before the old file was deleted; the document is then pruned. The
    // vault deletes the row's (new) file and the row; settle() finds no row and deletes `new` again, never `old`.
    test('killed after the switch and before the old-file delete, document deleted before the next run: no file left', function () {
        $old = $this->document->front_path;
        $new = dirname($old).'/switched';
        r253Pending($this->document, $new);
        Storage::disk(KycDocument::DISK)->put($new, r253Only($this->new)->encrypt('passport-scan', false));
        DB::table('kyc_documents')->where('id', $this->document->id)->update(['front_path' => $new]);
        expect(app(DocumentVault::class)->delete($this->document->fresh()))->toBeTrue()
            ->and(r253Files())->toBe([$old]);

        r253Run();

        expect(r253Files())->toBe([], 'the old-key identity image of a deleted document is left on the disk');
    });

    // Window 3 again, but the document was given another file (a re-upload) instead of being deleted.
    test('killed after the switch, row moved to a third file before the next run: only that file is left', function () {
        $old = $this->document->front_path;
        $new = dirname($old).'/switched';
        $third = dirname($old).'/reuploaded';
        r253Pending($this->document, $new);
        Storage::disk(KycDocument::DISK)->put($new, r253Only($this->new)->encrypt('passport-scan', false));
        Storage::disk(KycDocument::DISK)->put($third, r253Only($this->new)->encrypt('new-scan', false));
        DB::table('kyc_documents')->where('id', $this->document->id)->update(['front_path' => $third]);
        Storage::disk(KycDocument::DISK)->delete($new); // the writer deletes the file it replaced

        r253Run();

        expect(r253Files())->toBe([$third]);
    });

    // Live, window "before the switch": the vault deletes the document right after the pending record is saved.
    test('a document deleted while the new file is being written: no file left and the row stays gone', function () {
        $deleted = false;
        DB::listen(function (QueryExecuted $query) use (&$deleted): void {
            if (! $deleted && str_contains($query->sql, 'reencryption_checkpoints') && str_contains(json_encode($query->bindings), 'front_path')) {
                $deleted = true;
                app(DocumentVault::class)->delete(KycDocument::query()->findOrFail($this->document->id));
            }
        });

        r253Run(3); // the row changed under the run: "changed", run again

        expect($deleted)->toBeTrue()->and(KycDocument::query()->find($this->document->id))->toBeNull()
            ->and(r253Files())->toBe([]);
    });

    // Live, window "after the switch commits, before the old-file delete".
    test('a document deleted right after the switch commits: no file left', function () {
        $deleted = false;
        Event::listen(TransactionCommitted::class, function () use (&$deleted): void {
            if (! $deleted && DB::table('kyc_documents')->where('id', $this->document->id)->value('front_path') !== $this->document->front_path) {
                $deleted = true;
                app(DocumentVault::class)->delete(KycDocument::query()->findOrFail($this->document->id));
            }
        });

        r253Run();

        expect($deleted)->toBeTrue()->and(KycDocument::query()->find($this->document->id))->toBeNull()
            ->and(r253Files())->toBe([]);
    });
    // Two runs at once (the 6 h lock expired, or CACHE_STORE is not shared between the containers): run B starts while
    // run A has written and checked its new file and is about to switch the row. B settles A's pending record and
    // deletes A's new file; A then switches the row to it and deletes the old one. Before the pending record this
    // overlap was harmless (the c89c1da7 probe with an `array` lock store); now the row points at a missing image.
    test('a second run that starts while the first is between its check and its switch loses no identity image', function () {
        $settled = false;
        Event::listen(TransactionBeginning::class, function () use (&$settled): void {
            $pending = ReencryptionCheckpoint::query()->where('target', 'kyc_documents.files')->value('pending');

            if (! $settled && $pending !== null) {
                $settled = true;
                // Run B's start: Reencryptor::process() settles the pending record before its first chunk.
                $b = app(Reencryptor::class);
                $checkpoint = new ReflectionMethod($b, 'checkpoint');
                $checkpoint->invoke($b, 'kyc_documents.files', app(EncryptedTargets::class)->all()['kyc_documents.files'], false);
            }
        });

        r253Run();

        $path = $this->document->fresh()->front_path;
        expect($settled)->toBeTrue()
            ->and(Storage::disk(KycDocument::DISK)->exists($path))->toBeTrue('the row points at an identity image that was deleted');
    });
});

describe('PR #253 re-check: partial runs', function () {
    // --only is a partial run too. It ends with exit 0 and "every stored value is on the current key" while other
    // targets are still on the old key (documented here; the runbook's verify step is a full dry run).
    test('a real run with --only does not claim that every stored value is on the current key', function () {
        $old = r253Key();
        r253Restart($old);
        Server::factory()->create(['password' => 'p']);
        r253Restart(r253Key(), [$old]);

        test()->artisan('security:reencrypt', ['--only' => ['settings.value']])
            ->doesntExpectOutputToContain('every stored value is on the current key');
    });
});
