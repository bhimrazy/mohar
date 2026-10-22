<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\StoreDocument;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToDeleteFile;

/**
 * Review board probes for #228 (KYC part 1). Each one fails at 63d8f499 and passes once its finding is fixed.
 */
beforeEach(function () {
    Storage::fake(KycDocument::DISK);
});

function probePng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kyc');
    file_put_contents($path, (string) fakePng('front.png', 8, 8)->get());

    return new UploadedFile($path, 'front.png', null, null, true);
}

test('B1: with verification off, a document upload answers 404 whatever its body (validation must not run first)', function () {
    actingAsClient();
    app(Settings::class)->set('kyc.enabled', false);

    // At 63d8f499 the FormRequest validates before the controller's enabled() check: 302 with errors, not 404.
    $this->post(route('client.kyc.documents.store'), [])->assertNotFound();
    $this->post(route('client.kyc.documents.store'), ['type' => 'citizenship'])->assertNotFound();
});

test('M1: an upload that commits after the account was closed is still scheduled for deletion (or refused)', function () {
    $client = Client::factory()->create();
    // The request resolved the client while it was open; the close (and its retention listener) committed first.
    $client->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    try {
        $document = app(StoreDocument::class)->handle($client->id, DocumentType::Citizenship, '27-01-74-01234', probePng(), probePng(), null);
    } catch (ValidationException) {
        expect(KycDocument::query()->count())->toBe(0);

        return;
    }

    // At 63d8f499 delete_after stays null: the documents of a closed account are kept forever.
    expect($document->fresh()->delete_after)->not->toBeNull();
});

test('M2: a failed deletion is logged without the storage path (spec 5 and 6)', function () {
    $document = KycDocument::factory()->create();
    $path = $document->front_path;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(UnableToDeleteFile::atLocation($path, 'access denied'));
    Storage::set(KycDocument::DISK, $disk);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_SLASHES);
    });

    expect(app(DocumentVault::class)->delete($document))->toBeFalse()
        ->and(KycDocument::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain($path);
});
