<?php

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Billing/Support/race.php';
require_once __DIR__.'/Support/image-fixtures.php';

/**
 * `kyc:reencode-images` (#287) against a client replacing the same document, in two processes with committed
 * transactions (ADR 0021 §4). Lock order: client, then document, in both. Whichever switches first, the replaced
 * document is deleted with whatever files it points at by then, and no image file is left without a row.
 */
describe('re-encoding versus a replacement upload in real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }

        $this->root = sys_get_temp_dir().'/kyc287-race-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.objects' => ['driver' => 'local', 'root' => $this->root, 'throw' => true]]);
        Storage::forgetDisk(KycDocument::DISK);
    });

    afterEach(function () {
        File::deleteDirectory($this->root);
    });

    test('the replaced document is not brought back and no image is left without a row', function (string $how, string $delay, string $done) {
        $this->useSeededDatabase('kyc287_race_'.$how, function (): void {
            $client = Client::factory()->create(['email' => 'kyc287-race@example.test']);
            KycDocument::factory()->create(['client_id' => $client->id, 'type' => DocumentType::Citizenship, 'front_mime' => 'image/jpeg']);
        });
        $client = Client::query()->where('email', 'kyc287-race@example.test')->sole();
        $old = KycDocument::query()->where('client_id', $client->id)->sole();

        foreach ($old->paths() as $path) {
            Storage::disk(KycDocument::DISK)->put($path, Crypt::encryptString(reencodeImage('jpeg', 6)));
        }

        $database = DB::connection()->getDatabaseName();
        // hold: the run locks the document and keeps it 1.5 s; the replacement starts 0.3 s in and waits for the client
        // lock, then deletes the files the row points at after the switch. wait: the run pauses 1.5 s before locking;
        // the replacement has deleted the row by then, so the run switches nothing and deletes its new files.
        $results = runRaceWorkers([
            'run' => ['run', $database, $this->root, $how, '1500'],
            'replace' => ['replace', $database, $this->root, (string) $client->id, $delay],
        ], 60.0, __DIR__.'/Support/reencode-race-worker.php');

        $current = KycDocument::query()->where('client_id', $client->id)->sole();
        $files = collect(File::allFiles($this->root))->map(fn (SplFileInfo $file): string => str_replace($this->root.'/', '', $file->getPathname()))->all();

        expect($results['replace']['outcome'])->toBe('stored')
            ->and($results['run']['outcome'])->toBe(0, $results['run']['output'])
            ->and($results['run']['output'])->toContain($done)
            ->and($current->id)->toBe($results['replace']['document'])
            ->and(KycDocument::query()->find($old->id))->toBeNull()
            ->and($files)->toEqualCanonicalizing($current->paths())
            ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);

        DB::table('kyc_documents')->delete();
        DB::table('activity_log')->delete();
    })->with([
        'the run switches first' => ['hold', '300', 'Done: 1 document(s) re-encoded'],
        'the client replaces first' => ['wait', '0', 'Done: 0 document(s) re-encoded'],
    ]);
});
