<?php

/*
 * Second re-check probes for PR #253 at 9f07a152.
 *
 * Expected at 9f07a152:
 * - pass (guards): an upgraded install whose staged rows were purged, migrated before the rotation; a real --only run
 *   records `only` and `complete: false` in its audit entry.
 * - fail (findings): the same purged install upgraded and rotated in one restart (the email-hash secret is pinned from
 *   the new key because the record it checks against has no `normalized` left); a real --only run exits 0 although
 *   other targets are still on the old key (ADR 0022 §3 and the runbook's exit table make 0 the "retire the old key"
 *   signal).
 */

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Services\Models\Server;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

function r253b(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function r253bRestart(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
    app()->forgetInstance(InstallSecrets::class);
}

function r253bFile(): UploadedFile
{
    $header = ['First Name', 'Last Name', 'Email Address', 'Country', 'Status'];

    return ClientCsv::file([['Asha', 'Rai', 'asha@example.test', 'NP', 'Active'], ['Ram', 'Thapa', 'ram@example.test', 'NP', 'Active']], $header);
}

/** Import, simulate the upgrade (no secret row) and the retention purge (MigrationServiceProvider::purgeExpired()), run $between, re-import. */
function r253bPurgedUpgrade(Closure $between): MigrationRun
{
    $first = ClientCsv::check(ClientCsv::upload(r253bFile()));
    $source = ClientCsv::apply($first)->migration_source_id;
    expect(Client::query()->count())->toBe(2)->and(MigrationIdMap::query()->count())->toBe(2);

    DB::table('install_secrets')->delete();
    app()->forgetInstance(InstallSecrets::class); // the upgraded process has no memoized secret
    MigrationRecord::query()->update(['raw' => null, 'normalized' => null, 'before' => null]);
    expect(MigrationRecord::query()->whereNotNull('email_hash')->count())->toBe(2);

    $between();

    return ClientCsv::check(ClientCsv::upload(r253bFile(), ['source_id' => $source]));
}

function r253bMigrate(): void
{
    (require base_path('app/Modules/Migration/Database/Migrations/2026_10_22_980300_seed_email_hash_secret.php'))->up();
}

describe('PR #253 re-check 2: email-hash secret on an upgraded install whose rows were purged', function () {
    beforeEach(function () {
        Storage::fake('objects');
        Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
        actingAsStaff();
        confirmPassword();
    });

    test('guard: upgrade (migrate) under the old key, rotate later: the re-import still matches', function () {
        [$k1, $k2] = [r253b(), r253b()];
        r253bRestart($k1);

        $rerun = r253bPurgedUpgrade(function () use ($k1, $k2): void {
            r253bMigrate();
            r253bRestart($k2, [$k1]);
            test()->artisan('security:reencrypt')->assertExitCode(0);
            r253bRestart($k2);
        });

        expect($rerun->unchanged_count)->toBe(2)->and($rerun->create_count)->toBe(0)
            ->and(MigrationIdMap::query()->count())->toBe(2);
    });

    // The deploy that brings this version also carries the new APP_KEY (the operator upgrades in order to rotate):
    // migrate runs with K2 current and K1 previous. emailHashKey() checks the oldest record with an email_hash, whose
    // `normalized` was purged, so it cannot tell which key made the hashes and falls back to the current key.
    test('upgrade and rotation in one restart: the re-import still matches', function () {
        [$k1, $k2] = [r253b(), r253b()];
        r253bRestart($k1);

        $rerun = r253bPurgedUpgrade(function () use ($k1, $k2): void {
            r253bRestart($k2, [$k1]);
            r253bMigrate();
            test()->artisan('security:reencrypt')->assertExitCode(0);
            r253bRestart($k2);
        });

        expect($rerun->unchanged_count)->toBe(2)->and($rerun->create_count)->toBe(0)
            ->and(MigrationIdMap::query()->count())->toBe(2);
    });
});

describe('PR #253 re-check 2: --only is a partial run', function () {
    beforeEach(function () {
        $old = r253b();
        r253bRestart($old);
        Server::factory()->create(['password' => 'p']);
        r253bRestart(r253b(), [$old]);
    });

    test('guard: a real --only run records only and complete: false', function () {
        test()->artisan('security:reencrypt', ['--only' => ['settings.value']])->run();

        $log = ActivityLog::query()->where('event', 'security.reencrypted')->sole();
        expect($log->properties['only'])->toBe(['settings.value'])->and($log->properties['complete'])->toBeFalse();
    });

    // Exit 0 is the documented "every stored value is on the current key" and ADR 0022's gate for retiring the old
    // key; --partial (modules skipped) and --limit already end with 3 for the same reason.
    test('a real --only run with other targets on the old key does not exit 0', function () {
        test()->artisan('security:reencrypt', ['--only' => ['settings.value']])->assertExitCode(3);
    });
});
