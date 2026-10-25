<?php

/*
 * The email-hash install secret on an upgraded install (ADR 0022): it is pinned from the configured key that
 * reproduces a stored hash, and it is never guessed when several keys are configured and nothing can be checked.
 */

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Core\Support\InstallSecretUnavailable;
use App\Modules\Migration\Models\MigrationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

function ehsKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function ehsRestart(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
    app()->forgetInstance(InstallSecrets::class);
}

function ehsMigrate(): void
{
    (require base_path('app/Modules/Migration/Database/Migrations/2026_10_22_980300_seed_email_hash_secret.php'))->up();
}

beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();

    [$this->k1, $this->k2] = [ehsKey(), ehsKey()];
    ehsRestart($this->k1);
    $file = ClientCsv::file([['Asha', 'Rai', 'asha@example.test', 'NP', 'Active']], ['First Name', 'Last Name', 'Email Address', 'Country', 'Status']);
    ClientCsv::apply(ClientCsv::check(ClientCsv::upload($file)));

    // An upgraded install after the retention purge, whose imported client has since changed email: no stored hash
    // can be checked against an email.
    DB::table('install_secrets')->delete();
    MigrationRecord::query()->update(['raw' => null, 'normalized' => null, 'before' => null]);
    Client::query()->update(['email' => 'changed@example.test']);
});

test('upgrade and rotation in one restart with nothing to check: migrate and security:reencrypt refuse, nothing is pinned', function () {
    ehsRestart($this->k2, [$this->k1]);

    expect(fn () => ehsMigrate())->toThrow(InstallSecretUnavailable::class, 'then run `php artisan migrate` again');

    ehsRestart($this->k2, [$this->k1]);
    $this->artisan('security:reencrypt')->expectsOutputToContain('match none of the configured keys')->assertExitCode(2);

    expect(DB::table('install_secrets')->count())->toBe(0);
});

test('with only the key in use configured, the upgrade pins that key without a check', function () {
    ehsRestart($this->k1);
    ehsMigrate();

    expect(DB::table('install_secrets')->count())->toBe(1)
        ->and(MigrationRecord::emailHash('asha@example.test'))->toBe(MigrationRecord::query()->value('email_hash'));
});
