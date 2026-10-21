<?php

use App\Events\Core\ClientClosed;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Listeners\ScheduleDocumentRetention;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
});

function rcPng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kyc');
    file_put_contents($path, (string) fakePng('front.png', 8, 8)->get());

    return new UploadedFile($path, 'front.png', null, null, true);
}

test('R1: replacing an approved document (action-level validation error) does not flash the number', function () {
    [, $client] = actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
    KycDocument::factory()->approved()->create(['client_id' => $client->id, 'type' => 'citizenship']);

    $this->from(route('client.kyc.show'))->post(route('client.kyc.documents.store'), [
        'type' => 'citizenship', 'number' => '27-01-74-99999', 'front' => rcPng(), 'back' => rcPng(),
    ])->assertSessionHasErrors('type')->assertSessionMissing('_old_input.number');
});

test('R2: a failed discard of a failed upload logs no path', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(UnableToDeleteFile::atLocation('kyc/1/abc', 'denied'));
    Storage::set(KycDocument::DISK, $disk);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
    });

    app(DocumentVault::class)->discard(['kyc/1/abc']);

    expect($logged)->not->toBeEmpty()->and(implode("\n", $logged))->not->toContain('kyc/1/abc')->toContain('UnableToDeleteFile');
});

test('R3: the retention listener locks the client before it updates the documents, in one transaction', function () {
    $client = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $client->id]);
    $client->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $sql = [];
    DB::listen(function ($q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    app(ScheduleDocumentRetention::class)->handle(new ClientClosed($client->id));

    $lock = collect($sql)->search(fn ($s) => str_contains($s, 'from "clients"'));
    $update = collect($sql)->search(fn ($s) => str_starts_with($s, 'update "kyc_documents"'));
    expect($lock)->not->toBeFalse()->and($update)->toBeGreaterThan($lock);
    if (DB::getDriverName() === 'pgsql') {
        expect($sql[$lock])->toContain('for update');
    }
    expect(KycDocument::query()->sole()->delete_after)->not->toBeNull();
});
