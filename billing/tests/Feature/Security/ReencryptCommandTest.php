<?php

/*
 * `security:reencrypt` (#25): rotation end to end, resume after an interrupt, dry run, an undecryptable row and a
 * concurrent write. Keys are switched in the container the way a restart with a new APP_KEY / APP_PREVIOUS_KEYS does.
 */

use App\Models\User;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\ReencryptionCheckpoint;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Services\Models\Server;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

function newKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/** Restart with these keys: a fresh encrypter, as every process gets after a deploy. */
function useKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Facade::clearResolvedInstance('encrypter');
}

function onlyKey(string $key): Encrypter
{
    return new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
}

function rawValue(string $table, string $column, int|string $id, string $keyColumn = 'id'): string
{
    return (string) DB::table($table)->where($keyColumn, $id)->value($column);
}

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->old = newKey();
    $this->new = newKey();
    useKeys($this->old);
});

test('a rotation re-encrypts columns, secret settings, 2FA secrets and identity files, then works without the old key', function () {
    $server = Server::factory()->create(['password' => 'server-pass', 'api_token' => 'tok-123']);
    $gateway = Gateway::factory()->create(['config' => ['secret_key' => 'sk_live_abc']]);
    app(Settings::class)->set('support.inbound_secret', str_repeat('s', 40));
    $user = User::factory()->withTwoFactor()->create();
    $document = KycDocument::factory()->create(['back_path' => null]);
    Storage::disk(KycDocument::DISK)->put($document->front_path, onlyKey($this->old)->encrypt('image-bytes', false));
    $oldFront = $document->front_path;

    useKeys($this->new, [$this->old]);

    $this->artisan('security:reencrypt')->assertExitCode(0);

    useKeys($this->new); // the old key is gone; the command itself dropped the cached settings
    $current = onlyKey($this->new);
    $document->refresh();

    expect($current->decrypt(rawValue('servers', 'password', $server->id), false))->toBe('server-pass')
        ->and($server->fresh()->api_token)->toBe('tok-123')
        ->and($gateway->fresh()->config)->toBe(['secret_key' => 'sk_live_abc'])
        ->and(app(Settings::class)->get('support.inbound_secret'))->toBe(str_repeat('s', 40))
        ->and(decrypt($user->fresh()->two_factor_secret))->toBe('secret')
        ->and($document->front_path)->not->toBe($oldFront)->toStartWith(dirname($oldFront).'/')
        ->and($current->decrypt(Storage::disk(KycDocument::DISK)->get($document->front_path), false))->toBe('image-bytes')
        ->and(Storage::disk(KycDocument::DISK)->exists($oldFront))->toBeFalse()
        ->and(ReencryptionCheckpoint::query()->count())->toBe(0);

    // One audit summary with counts only: no ciphertext, no plaintext.
    $log = ActivityLog::query()->where('event', 'security.reencrypted')->sole();
    $json = json_encode($log->properties);
    expect($log->properties['complete'])->toBeTrue()
        ->and($log->properties['targets']['servers.password']['reencrypted'])->toBe(1)
        ->and($log->properties['targets']['settings.value']['reencrypted'])->toBe(1)
        ->and($log->properties['targets']['kyc_documents.files']['reencrypted'])->toBe(1)
        ->and($json)->not->toContain('server-pass')->not->toContain('sk_live')->not->toContain('eyJ');

    // A second run finds everything on the current key.
    $this->artisan('security:reencrypt --dry-run')->expectsOutputToContain('Every stored value is on the current key.')->assertExitCode(0);
});

test('an interrupted run resumes after the last finished chunk', function () {
    $servers = Server::factory()->count(5)->create(['password' => 'p']);
    useKeys($this->new, [$this->old]);

    // Crash on the third write: chunk one (two rows) is checkpointed, row three is written, then the process dies.
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_starts_with($query->sql, 'update "servers"') && ++$writes === 3) {
            throw new RuntimeException('killed');
        }
    });

    expect(fn () => $this->artisan('security:reencrypt', ['--only' => ['servers.password'], '--chunk' => 2])->run())->toThrow(RuntimeException::class, 'killed');
    expect(ReencryptionCheckpoint::query()->sole()->last_key)->toBe((string) $servers[1]->id);

    $writes = -100; // no more crashes
    $this->artisan('security:reencrypt', ['--only' => ['servers.password'], '--chunk' => 2])->assertExitCode(0);

    $totals = ActivityLog::query()->where('event', 'security.reencrypted')->sole()->properties['totals'];
    // Rows 1-2 came from the checkpoint; row 3 was already re-encrypted by the killed run; 4-5 now.
    expect($totals)->toMatchArray(['values' => 5, 'current' => 1, 'old_key' => 4, 'reencrypted' => 4])
        ->and($servers->every(fn (Server $server): bool => onlyKey($this->new)->decrypt(rawValue('servers', 'password', $server->id), false) === 'p'))->toBeTrue();
});

test('--limit stops early with exit code 3 and the next run continues', function () {
    Server::factory()->count(3)->create(['password' => 'p']);
    useKeys($this->new, [$this->old]);

    $this->artisan('security:reencrypt', ['--only' => ['servers.password'], '--limit' => 2])->assertExitCode(3);
    expect(ReencryptionCheckpoint::query()->sole()->completed)->toBeFalse();

    $this->artisan('security:reencrypt', ['--only' => ['servers.password']])->assertExitCode(0);
    expect(ReencryptionCheckpoint::query()->count())->toBe(0);
});

test('a dry run counts current, old-key and undecryptable values and changes nothing', function () {
    $old = Server::factory()->create(['password' => 'a']);
    useKeys($this->new, [$this->old]);
    $current = Server::factory()->create(['password' => 'b']);
    $before = DB::table('servers')->orderBy('id')->pluck('password')->all();

    $this->artisan('security:reencrypt', ['--dry-run' => true, '--only' => ['servers.password']])
        ->expectsTable(['Target', 'Values', 'Current key', 'Old key', 'Re-encrypted', 'Undecryptable', 'Changed / failed / missing'], [['servers.password', 2, 1, 1, '-', 0, '-']])
        ->assertExitCode(3);

    $stranger = Server::factory()->create();
    DB::table('servers')->where('id', $stranger->id)->update(['password' => onlyKey(newKey())->encrypt('x', false)]);

    $this->artisan('security:reencrypt', ['--dry-run' => true, '--only' => ['servers.password']])
        ->expectsOutputToContain("no key decrypts the value of {$stranger->id}")
        ->assertExitCode(1);

    expect(DB::table('servers')->whereIn('id', [$old->id, $current->id])->orderBy('id')->pluck('password')->all())->toBe($before)
        ->and(ReencryptionCheckpoint::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'security.reencrypted')->count())->toBe(0);
});

test('a value no key decrypts is left unchanged, reported by id, and fails the run', function () {
    $good = Server::factory()->create(['password' => 'good']);
    $bad = Server::factory()->create();
    $foreign = onlyKey(newKey())->encrypt('lost', false);
    DB::table('servers')->where('id', $bad->id)->update(['password' => $foreign]);
    useKeys($this->new, [$this->old]);

    $this->artisan('security:reencrypt', ['--only' => ['servers.password']])
        ->expectsOutputToContain("no key decrypts the value of {$bad->id}")
        ->assertExitCode(1);

    expect(rawValue('servers', 'password', $bad->id))->toBe($foreign)
        ->and(onlyKey($this->new)->decrypt(rawValue('servers', 'password', $good->id), false))->toBe('good')
        ->and(ActivityLog::query()->where('event', 'security.reencrypted')->sole()->properties['totals']['undecryptable'])->toBe(1);
});

test('a write by the app during the run wins over the re-encryption', function () {
    $first = Server::factory()->create(['password' => 'one']);
    $second = Server::factory()->create(['password' => 'two']);
    useKeys($this->new, [$this->old]);

    // Right after the command reads the chunk, staff save a new password for the second server.
    $done = false;
    DB::listen(function (QueryExecuted $query) use (&$done, $second): void {
        if (! $done && str_contains($query->sql, 'from "servers"') && str_contains($query->sql, 'limit')) {
            $done = true;
            DB::table('servers')->where('id', $second->id)->update(['password' => Crypt::encryptString('changed-by-staff')]);
        }
    });

    $this->artisan('security:reencrypt', ['--only' => ['servers.password']])
        ->expectsOutputToContain('written by the app during the run')
        ->assertExitCode(3);

    expect($second->fresh()->password)->toBe('changed-by-staff')
        ->and(onlyKey($this->new)->decrypt(rawValue('servers', 'password', $first->id), false))->toBe('one')
        ->and(ActivityLog::query()->where('event', 'security.reencrypted')->sole()->properties['totals']['changed'])->toBe(1);
});

test('a real run refuses to start while modules are missing, unless --partial; another run holding the lock is exit 4', function () {
    Server::factory()->create(['password' => 'p']);
    useKeys($this->new, [$this->old]);
    config(['modules.only' => 'Services']);

    $this->artisan('security:reencrypt')->expectsOutputToContain('Modules not loaded: Billing')->assertExitCode(2);
    $this->artisan('security:reencrypt --dry-run')->assertExitCode(3);
    $this->artisan('security:reencrypt --partial')->assertExitCode(3);
    expect(ActivityLog::query()->where('event', 'security.reencrypted')->sole()->properties['skipped_modules'])->toContain('Billing')->not->toContain('Services');

    config(['modules.only' => '']);
    $lock = Cache::lock('security:reencrypt', 60);
    $lock->get();
    $this->artisan('security:reencrypt')->expectsOutputToContain('Another security:reencrypt run is in progress')->assertExitCode(4);
    $lock->release();
});

test('invalid options exit with code 2', function () {
    $this->artisan('security:reencrypt', ['--chunk' => 0])->assertExitCode(2);
    $this->artisan('security:reencrypt', ['--only' => ['nope.column']])->assertExitCode(2);
});

test('a run killed after switching an identity image but before deleting the old file removes it on the next run', function () {
    $document = KycDocument::factory()->create(['back_path' => null]);
    $oldPath = $document->front_path;
    Storage::disk(KycDocument::DISK)->put($oldPath, onlyKey($this->old)->encrypt('scan', false));
    useKeys($this->new, [$this->old]);

    // The state a run killed right after the switch commits leaves: the row on the new file, the old file still there
    // and the pending record (written under another key's fingerprint, which settling does not depend on).
    $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode(0);
    $newPath = $document->fresh()->front_path;
    Storage::disk(KycDocument::DISK)->put($oldPath, onlyKey($this->old)->encrypt('scan', false));
    ReencryptionCheckpoint::query()->create(['target' => 'kyc_documents.files', 'key_fingerprint' => 'x', 'counts' => [], 'pending' => ['key' => $document->id, 'column' => 'front_path', 'old' => $oldPath, 'new' => $newPath]]);

    $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode(0);

    expect(Storage::disk(KycDocument::DISK)->allFiles())->toBe([$newPath])
        ->and(onlyKey($this->new)->decrypt(Storage::disk(KycDocument::DISK)->get($newPath), false))->toBe('scan');
});

test('when an overlapping run that cannot see the lock removes the new file, the row is not switched to it', function () {
    $document = KycDocument::factory()->create(['back_path' => null]);
    $oldPath = $document->front_path;
    Storage::disk(KycDocument::DISK)->put($oldPath, onlyKey($this->old)->encrypt('scan', false));
    useKeys($this->new, [$this->old]);

    // Right before the switch, another run (no shared lock store) settles the pending record and deletes the new file.
    $removed = false;
    Event::listen(TransactionBeginning::class, function () use (&$removed): void {
        $pending = ReencryptionCheckpoint::query()->where('target', 'kyc_documents.files')->value('pending');

        if (! $removed && $pending !== null) {
            $removed = true;
            Storage::disk(KycDocument::DISK)->delete((is_array($pending) ? $pending : json_decode((string) $pending, true))['new']);
        }
    });

    $this->artisan('security:reencrypt', ['--only' => ['kyc_documents.files']])->assertExitCode(3);

    expect($removed)->toBeTrue()
        ->and($document->fresh()->front_path)->toBe($oldPath)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([$oldPath]);
});
