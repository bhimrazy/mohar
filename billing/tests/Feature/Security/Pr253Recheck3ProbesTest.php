<?php

/*
 * Third re-check probes for PR #253 at d8e07214.
 *
 * Expected at d8e07214:
 * - pass (guards): a fresh install with two keys configured migrates and imports; hashes that survive only in the
 *   id map still identify the old key in an upgrade-and-rotate restart; the recovery exactly as worded (APP_KEY back
 *   to the old key, APP_PREVIOUS_KEYS empty) migrates; --only --dry-run ends with 3.
 * - fail (finding): the recovery an operator most likely does after the refusal, reverting APP_KEY to the old key
 *   and leaving APP_PREVIOUS_KEYS as set for the rotation (it lists that same old key), still refuses, because the
 *   key count is not de-duplicated (MigrationRecord::emailHashKey(), `count($keys) <= 1`).
 */

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Core\Support\InstallSecretUnavailable;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Services\Models\Server;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

function r253c(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function r253cRestart(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
    app()->forgetInstance(InstallSecrets::class);
}

function r253cFile(): UploadedFile
{
    return ClientCsv::file([['Asha', 'Rai', 'asha@example.test', 'NP', 'Active']], ['First Name', 'Last Name', 'Email Address', 'Country', 'Status']);
}

function r253cMigrate(): void
{
    (require base_path('app/Modules/Migration/Database/Migrations/2026_10_22_980300_seed_email_hash_secret.php'))->up();
}

/** An upgraded install (no secret row) after the retention purge whose client changed email: nothing can be checked. */
function r253cUncheckable(string $k1): void
{
    r253cRestart($k1);
    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(r253cFile())));
    DB::table('install_secrets')->delete();
    MigrationRecord::query()->update(['raw' => null, 'normalized' => null, 'before' => null]);
    Client::query()->update(['email' => 'changed@example.test']);
}

describe('PR #253 re-check 3: the email-hash key check', function () {
    beforeEach(function () {
        Storage::fake('objects');
        Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
        actingAsStaff();
        confirmPassword();
    });

    test('guard: a fresh install with two keys configured migrates and imports', function () {
        [$k1, $k2] = [r253c(), r253c()];
        r253cRestart($k2, [$k1]);

        r253cMigrate();
        expect(DB::table('install_secrets')->count())->toBe(0);

        $run = ClientCsv::check(ClientCsv::upload(r253cFile()));
        expect($run->create_count)->toBe(1)->and(DB::table('install_secrets')->count())->toBe(1);
    });

    test('guard: hashes kept only in the id map identify the old key in an upgrade-and-rotate restart', function () {
        [$k1, $k2] = [r253c(), r253c()];
        r253cRestart($k1);
        $source = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(r253cFile())))->migration_source_id;

        DB::table('install_secrets')->delete();
        MigrationRecord::query()->delete();
        expect(MigrationIdMap::query()->where('source_id', 'like', 'email:%')->count())->toBe(1);

        r253cRestart($k2, [$k1]);
        r253cMigrate();
        r253cRestart($k2);

        $rerun = ClientCsv::check(ClientCsv::upload(r253cFile(), ['source_id' => $source]));
        expect($rerun->unchanged_count)->toBe(1)->and($rerun->create_count)->toBe(0);
    });

    test('guard: the recovery as worded (old key as APP_KEY, no previous keys) migrates', function () {
        [$k1, $k2] = [r253c(), r253c()];
        r253cUncheckable($k1);

        r253cRestart($k2, [$k1]);
        expect(fn () => r253cMigrate())->toThrow(InstallSecretUnavailable::class);

        r253cRestart($k1);
        r253cMigrate();
        expect(DB::table('install_secrets')->count())->toBe(1);
    });

    // After the refusal the operator reverts APP_KEY to K1 and leaves APP_PREVIOUS_KEYS=K1 from the rotation. Only one
    // distinct key is configured, but count($keys) is 2, so migrate refuses again with the same advice.
    test('the recovery with APP_PREVIOUS_KEYS still listing the restored key migrates', function () {
        [$k1, $k2] = [r253c(), r253c()];
        r253cUncheckable($k1);

        r253cRestart($k2, [$k1]);
        expect(fn () => r253cMigrate())->toThrow(InstallSecretUnavailable::class);

        r253cRestart($k1, [$k1]);
        r253cMigrate();
        expect(DB::table('install_secrets')->count())->toBe(1);
    });
});

describe('PR #253 re-check 3: exit codes', function () {
    test('guard: --only --dry-run ends with 3 even when the selected targets are current', function () {
        r253cRestart(r253c());
        Server::factory()->create(['password' => 'p']);

        test()->artisan('security:reencrypt', ['--only' => ['servers.password'], '--dry-run' => true])->assertExitCode(3);
        test()->artisan('security:reencrypt', ['--dry-run' => true])->assertExitCode(0);
    });
});
