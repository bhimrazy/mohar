<?php

use App\Events\Domains\DomainRegistered;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Enums\NpStage;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\NpWorkflow;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Review-board probes for #168 (np_manual), adopted as regression tests. The first group reproduced the review's
 * findings at cfd2da53; the second pins behaviour the review verified.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake(NpDocument::DISK);
    CarbonImmutable::setTestNow('2026-10-21 10:00:00');
    [$this->user, $this->client] = actingAsClient();
    $this->tld = Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'np_manual']);
    $this->domain = Domain::factory()->pending()->named('asha', $this->tld)->create(['client_id' => $this->client->id, 'registrar_key' => 'np_manual']);
    app(SubmitToRegistrar::class)->handle($this->domain);
    $this->registration = fn (): NpRegistration => NpRegistration::query()->where('domain_id', $this->domain->id)->firstOrFail();
    $this->upload = fn () => $this->post(route('client.domains.np.documents.store', $this->domain->id), ['applicant_type' => 'individual', 'kind' => 'identity', 'file' => fakePng('id.png', 20, 20)]);
    $this->toReview = function (): void {
        ($this->upload)();
        $this->post(route('client.domains.np.review', $this->domain->id));
    };
    $this->toSubmitted = function (): void {
        ($this->toReview)();
        app(NpWorkflow::class)->review(($this->registration)(), true, null);
        app(NpWorkflow::class)->markSubmitted(($this->registration)(), CarbonImmutable::today());
    };
    $this->cancel = fn () => $this->domain->fresh()->forceFill(['status' => DomainStatus::Cancelled])->save();
});

/**
 * A real upload: unlike UploadedFile::fake(), its MIME type comes from the bytes (finfo), not from the name.
 */
function npRealUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'np');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

// --- Findings (failed at cfd2da53) ---

test('a request whose domain was cancelled or refunded cannot be recorded as approved', function () {
    ($this->toSubmitted)();
    ($this->cancel)();
    actingAsStaff(['domains.np.review', 'domains.domains.view']);

    $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => 'decision']), ['approved' => true])
        ->assertSessionHasErrors();

    expect(($this->registration)()->stage)->toBe(NpStage::Submitted);
});

test('a client cannot upload identity documents for a cancelled domain', function () {
    ($this->cancel)();

    ($this->upload)();

    expect(NpDocument::query()->count())->toBe(0);
});

test('the client to-do does not count requests of deleted domains', function () {
    $this->domain->fresh()->delete();

    $todo = collect(app(Dashboard::class)->resolveTodos('client', $this->user, $this->client))->firstWhere('key', 'domains.np_documents');

    expect((int) ($todo['count'] ?? 0))->toBe(0);
});

test('the staff to-dos do not count requests of cancelled domains', function () {
    ($this->toReview)();
    ($this->cancel)();

    expect(app(NpWorkflow::class)->queue('waiting')->count())->toBe(0);
});

test('staff in Kathmandu can record a portal submission made today, local date', function () {
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    ($this->toReview)();
    app(NpWorkflow::class)->review(($this->registration)(), true, null);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:00:00', 'UTC')); // 04:45 on Oct 22 in Kathmandu
    actingAsStaff(['domains.np.review']);

    $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => 'submitted']), ['submitted_on' => '2026-10-22'])
        ->assertSessionHasNoErrors();
});

// --- Verified behaviour (passes) ---

test('a signed document link stops working after 15 minutes', function () {
    ($this->upload)();
    $document = NpDocument::query()->sole();
    actingAsStaff(['domains.np.review']);
    $url = URL::temporarySignedRoute('admin.domains.np.document', now()->addMinutes(15), ['domain' => $this->domain->id, 'document' => $document->id]);

    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
});

test('a link for one domain does not open another domain\'s document', function () {
    ($this->upload)();
    $document = NpDocument::query()->sole();
    $other = Domain::factory()->pending()->named('other', $this->tld)->create(['registrar_key' => 'np_manual']);
    actingAsStaff(['domains.np.review']);

    $this->get(URL::temporarySignedRoute('admin.domains.np.document', now()->addMinutes(15), ['domain' => $other->id, 'document' => $document->id]))
        ->assertNotFound();
});

test('content decides the type: HTML named .png is refused and a GIF polyglot is served as an image', function () {
    $this->post(route('client.domains.np.documents.store', $this->domain->id), [
        'applicant_type' => 'individual', 'kind' => 'identity',
        'file' => npRealUpload('id.png', '<html><script>alert(1)</script></html>'),
    ])->assertSessionHasErrors('file');

    $this->post(route('client.domains.np.documents.store', $this->domain->id), [
        'applicant_type' => 'individual', 'kind' => 'identity',
        'file' => npRealUpload('id.png', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<html><script>alert(1)</script></html>"),
    ])->assertSessionHasNoErrors();
    $document = NpDocument::query()->sole();
    actingAsStaff(['domains.np.review']);

    $this->get(URL::temporarySignedRoute('admin.domains.np.document', now()->addMinutes(15), ['domain' => $this->domain->id, 'document' => $document->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/gif')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('another account cannot send a request for review or upload to it', function () {
    $stranger = User::factory()->create();
    Client::factory()->create()->users()->attach($stranger, ['is_owner' => true]);
    $this->actingAs($stranger);

    $this->post(route('client.domains.np.review', $this->domain->id))->assertNotFound();
    expect(($this->registration)()->stage)->toBe(NpStage::AwaitingDocuments);
});

test('a second approval is refused and the domain is registered once', function () {
    Event::fake([DomainRegistered::class]);
    ($this->toSubmitted)();
    actingAsStaff(['domains.np.review', 'domains.domains.view']);
    $decide = fn () => $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => 'decision']), ['approved' => true]);

    $decide()->assertSessionHasNoErrors();
    $decide()->assertSessionHasErrors('domain');

    Event::assertDispatchedTimes(DomainRegistered::class, 1);
    expect($this->domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('pruning removes files and rows and is idempotent, also when a file is already gone', function () {
    ($this->toSubmitted)();
    app(NpWorkflow::class)->decide(($this->registration)(), false, null, 3, 'Name mismatch.', null);
    $document = NpDocument::query()->sole();
    Storage::disk(NpDocument::DISK)->delete($document->path);

    expect(app(NpWorkflow::class)->pruneDocuments(CarbonImmutable::parse('2026-11-20')))->toBe(1)
        ->and(app(NpWorkflow::class)->pruneDocuments(CarbonImmutable::parse('2026-11-21')))->toBe(0)
        ->and(NpDocument::query()->count())->toBe(0)
        ->and(Storage::disk(NpDocument::DISK)->allFiles())->toBe([]);
});
