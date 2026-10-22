<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * Review board probes for #231 (KYC part 2). "B" probes fail at 2754ff47 and pass once the finding is fixed; "V"
 * probes pin behaviour the review verified as sound.
 */

require_once __DIR__.'/Support/race.php';

test('B1: unlocking one client does not reveal another client\'s document numbers or images', function () {
    actingAsStaff(['kyc.documents.review']);
    confirmPassword();
    $a = Client::factory()->create();
    $b = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $a->id]);
    KycDocument::factory()->create(['client_id' => $b->id, 'number' => '99-88-77-66666']);

    $this->post(route('admin.kyc.unlock', $a))->assertRedirect();

    // At 2754ff47 kyc.unlocked_until is one session flag: B's numbers and links show, with no audit entry for B.
    $this->get(route('admin.kyc.show', $b))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null)->where('documents.0.number', null)->where('documents.0.images', []));
});

describe('approve versus re-upload in real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('B2: a document is never both approved by staff and superseded by the client\'s replacement', function () {
        $this->useSeededDatabase('kyc_race', function (): void {
            $client = Client::factory()->create(['email' => 'kyc-race@example.test']);
            KycDocument::factory()->create(['client_id' => $client->id]);
            User::factory()->staff(['*'])->create(['email' => 'kyc-race-staff@example.test']);
        });
        $client = Client::query()->where('email', 'kyc-race@example.test')->sole();
        $document = KycDocument::query()->where('client_id', $client->id)->sole();
        $staff = User::query()->where('email', 'kyc-race-staff@example.test')->sole();
        $database = DB::connection()->getDatabaseName();

        // "upload" locks the client, reads the pending row, then pauses 1.5 s before superseding it; "decide" starts
        // 0.5 s later, locks the document row, and its activity_log insert (FK to clients) waits for the client lock.
        // At 2754ff47 the upload's UPDATE then waits for the document lock: 40P01 deadlock, one request fails.
        // Locking the client before the document in DecideDocument (ADR 0021 §3) makes the decision wait and refuse.
        $results = kycRaceWorkers([
            'upload' => ['upload', $database, (string) $client->id, '0', '1500'],
            'decide' => ['decide', $database, (string) $document->id, (string) $staff->id, '500'],
        ]);

        $old = KycDocument::query()->whereKey($document->id)->first();

        // One wins: approved and still the current document (the upload refused), or replaced (the decision refused).
        expect([$results['upload']['outcome'], $results['decide']['outcome']])->not->toBe(['stored', 'approved'])
            ->and($results['decide']['outcome'] === 'approved' ? $old?->superseded_at : null)->toBeNull()
            ->and($results['decide']['outcome'] === 'approved' ? $old?->status : DocumentStatus::Approved)->toBe(DocumentStatus::Approved);
    });
});

test('V1: an image link answers private, no-store with nosniff and the global CSP, and stops once the permission is removed', function () {
    Storage::fake(KycDocument::DISK);
    $staff = actingAsStaff(['kyc.documents.review']);
    confirmPassword();
    $document = KycDocument::factory()->create();
    Storage::disk(KycDocument::DISK)->put($document->front_path, Crypt::encryptString('front-bytes'));
    // Adopted in the fix round: links are bound to the staff member and need the client unlocked (#231 B1, M1).
    $this->post(route('admin.kyc.unlock', $document->client_id))->assertRedirect();
    $url = URL::temporarySignedRoute('admin.kyc.image', now()->addMinutes(15), ['document' => $document->id, 'side' => 'front', 'staff' => $staff->id]);

    $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain('default-src')
        ->and($response->headers->has('Referrer-Policy'))->toBeTrue();

    $staff->role->forceFill(['permissions' => ['kyc.settings.manage', 'kyc.documents.*']])->save();
    $staff->refresh();
    $this->get($url)->assertForbidden();
});
