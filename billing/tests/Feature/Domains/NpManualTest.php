<?php

use App\Events\Domains\DomainRegistered;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomains;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The .np manual-workflow registrar (registrars.md §9): the registry has no API, so staff carry each request
 * through documents, review, portal submission and the registry's answer.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake(NpDocument::DISK);
    CarbonImmutable::setTestNow('2026-10-21 10:00:00'); // a Wednesday
    [$this->user, $this->client] = actingAsClient();
    $this->tld = Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'np_manual']);
    $this->domain = Domain::factory()->pending()->named('asha', $this->tld)->create(['client_id' => $this->client->id, 'registrar_key' => 'np_manual', 'period_years' => 2]);
    app(SubmitToRegistrar::class)->handle($this->domain);
    $this->registration = fn (): NpRegistration => NpRegistration::query()->where('domain_id', $this->domain->id)->firstOrFail();
    $this->upload = fn (string $kind = 'identity', string $type = 'individual') => $this->post(route('client.domains.np.documents.store', $this->domain->id), [
        'applicant_type' => $type, 'kind' => $kind, 'file' => fakePng("{$kind}.png", 20, 20),
    ]);
    $this->toSubmitted = function (): void {
        ($this->upload)();
        $this->post(route('client.domains.np.review', $this->domain->id));
        actingAsStaff(['domains.np.review']);
        app(NpWorkflow::class)->review(($this->registration)(), true, null);
        app(NpWorkflow::class)->markSubmitted(($this->registration)(), CarbonImmutable::today());
    };
    $this->todo = fn (string $key): int => (int) (collect(app(Dashboard::class)->resolveTodos('admin', actingAsStaff(['*'])))->firstWhere('key', $key)['count'] ?? 0);
});

test('a paid .np order opens the staff workflow and waits for documents without calling anything', function () {
    expect($this->domain->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and(($this->registration)()->stage)->toBe(NpStage::AwaitingDocuments)
        ->and($this->domain->fresh()->commandLog()->where('command', 'register')->value('success'))->toBeTrue();
});

test('the request goes from documents to an active domain once the registry approves it', function () {
    Event::fake([DomainRegistered::class]);
    ($this->upload)()->assertRedirect(route('client.domains.show', $this->domain->id));
    $document = NpDocument::query()->sole();
    $this->post(route('client.domains.np.review', $this->domain->id))->assertRedirect();

    actingAsStaff(['domains.np.review', 'domains.domains.view']);
    $step = fn (string $action, array $data) => $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => $action]), $data)->assertSessionHasNoErrors();
    $step('review', ['approved' => true, 'checklist' => array_fill_keys(array_diff(NpWorkflow::CHECKLIST, ['letter_signed']), true)]);
    $step('submitted', ['submitted_on' => '2026-10-21']);
    $step('decision', ['approved' => true, 'registry_expires_on' => '2028-10-21']);

    expect($this->domain->fresh())->status->toBe(DomainStatus::Active)->expiry_date->toDateString()->toBe('2028-10-21')
        ->and(($this->registration)())->stage->toBe(NpStage::Approved)->review_due_on->toDateString()->toBe('2028-10-21')
        ->and(Storage::disk(NpDocument::DISK)->get($document->path))->not->toStartWith("\x89PNG")
        ->and($document->fresh()->delete_after->toDateString())->toBe('2026-11-20');
    Event::assertDispatched(DomainRegistered::class);
});

test('staff approve documents only with every check ticked, and organisations need the signed letter', function () {
    ($this->upload)('registration_certificate', 'organisation');
    ($this->upload)('request_letter', 'organisation');
    $this->post(route('client.domains.np.review', $this->domain->id))->assertSessionHasNoErrors();
    actingAsStaff(['domains.np.review']);

    $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => 'review']), [
        'approved' => true, 'checklist' => array_fill_keys(array_diff(NpWorkflow::CHECKLIST, ['letter_signed']), true),
    ])->assertSessionHasErrors('checklist.letter_signed');
    $this->post(route('admin.domains.np.step', ['domain' => $this->domain->id, 'action' => 'review']), ['approved' => false, 'reasons' => 'The letter has no stamp.']);

    expect(($this->registration)())->stage->toBe(NpStage::AwaitingDocuments)->returned_reasons->toBe('The letter has no stamp.');
});

test('a registry rejection reopens the request and turns urgent after two business days', function () {
    ($this->toSubmitted)();
    app(NpWorkflow::class)->decide(($this->registration)(), false, null, 4, 'The name does not match the citizenship certificate.', null);

    expect($this->domain->fresh()->status)->toBe(DomainStatus::Pending)
        ->and(($this->registration)()->stage)->toBe(NpStage::AwaitingDocuments)
        ->and(($this->todo)('domains.np_overdue'))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00'));
    expect(($this->todo)('domains.np_overdue'))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 11:00:00'));
    expect(($this->todo)('domains.np_overdue'))->toBe(1);
});

test('a submitted request is overdue after the staff SLA and waiting requests show as a to-do', function () {
    ($this->upload)();
    $this->post(route('client.domains.np.review', $this->domain->id));
    expect(($this->todo)('domains.np_waiting'))->toBe(1);

    app(NpWorkflow::class)->review(($this->registration)(), true, null);
    app(NpWorkflow::class)->markSubmitted(($this->registration)(), CarbonImmutable::today());
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00')); // four days, but Saturday is not a business day
    expect(($this->todo)('domains.np_overdue'))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 11:00:00'));
    expect(($this->todo)('domains.np_overdue'))->toBe(1);
});

test('documents are private to the account and to staff with the review permission, behind a signed link', function () {
    ($this->upload)();
    $document = NpDocument::query()->sole();
    $url = URL::temporarySignedRoute('admin.domains.np.document', now()->addMinutes(15), ['domain' => $this->domain->id, 'document' => $document->id]);

    $stranger = User::factory()->create();
    Client::factory()->create()->users()->attach($stranger, ['is_owner' => true]);
    $this->actingAs($stranger);
    $this->post(route('client.domains.np.documents.store', $this->domain->id), ['applicant_type' => 'individual', 'kind' => 'identity', 'file' => fakePng('x.png', 5, 5)])->assertNotFound();
    actingAsStaff(['domains.domains.view']);
    $this->get($url)->assertForbidden();
    actingAsStaff(['domains.np.review']);
    $this->get(route('admin.domains.np.document', ['domain' => $this->domain->id, 'document' => $document->id]))->assertForbidden();
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(Crypt::decryptString(Storage::disk(NpDocument::DISK)->get($document->path)))->toStartWith("\x89PNG");
});

test('uploads accept only images the registry takes, up to 800 KB', function (UploadedFile $file) {
    $this->post(route('client.domains.np.documents.store', $this->domain->id), ['applicant_type' => 'individual', 'kind' => 'identity', 'file' => $file])
        ->assertSessionHasErrors('file');

    expect(NpDocument::query()->count())->toBe(0);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('id.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'over 800 KB' => fn () => fakePng('id.png', 600, 600, noise: true),
]);

test('a renewal reschedules the registry check, which flags the domain once it passes unconfirmed', function () {
    ($this->toSubmitted)();
    app(NpWorkflow::class)->decide(($this->registration)(), true, null, null, null, null);
    $expiry = $this->domain->fresh()->expiry_date;

    $result = app(PerformRenewal::class)->handle($this->domain->fresh(), 1, $expiry->toDateString());

    expect($result->success)->toBeTrue()
        ->and($this->domain->fresh()->expiry_date->toDateString())->toBe($expiry->addYear()->toDateString())
        ->and(($this->registration)())->review_due_on->toDateString()->toBe('2028-10-21')->review_confirmed_at->toBeNull();

    expect(app(NpWorkflow::class)->flagOverdueReviews(CarbonImmutable::parse('2028-10-22')))->toBe(1)
        ->and($this->domain->fresh()->needs_review)->toBeTrue();
    app(NpWorkflow::class)->confirmReview(($this->registration)());
    expect($this->domain->fresh()->needs_review)->toBeFalse();
});

test('a renewal interrupted after the .np step ran is applied once when retried', function () {
    ($this->toSubmitted)();
    app(NpWorkflow::class)->decide(($this->registration)(), true, null, null, null, null);
    $expiry = $this->domain->fresh()->expiry_date;
    $this->domain->fresh()->forceFill(['registrar_meta' => ['renew_sent_for' => $expiry->toDateString(), 'renew_sent_at' => now()->subMinute()->toIso8601String()]])->save();
    app(NpWorkflow::class)->renewed($this->domain); // the driver ran, then the worker stopped

    expect(app(PerformRenewal::class)->handle($this->domain->fresh(), 1, $expiry->toDateString())->success)->toBeTrue()
        ->and($this->domain->fresh()->expiry_date->toDateString())->toBe($expiry->addYear()->toDateString())
        ->and(($this->registration)()->review_due_on->toDateString())->toBe('2028-10-21');
});

test('documents are deleted after the retention period, and with a cancelled order', function () {
    ($this->toSubmitted)();
    $kept = NpDocument::query()->sole();
    app(NpWorkflow::class)->decide(($this->registration)(), true, null, null, null, null);
    $other = Domain::factory()->pending()->named('bikash', $this->tld)->create(['registrar_key' => 'np_manual', 'status' => DomainStatus::Cancelled]);
    $orphan = app(NpWorkflow::class)->start($other)->documents()->create(['kind' => 'identity', 'path' => 'np-documents/x', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 1]);

    expect(app(NpWorkflow::class)->pruneDocuments(CarbonImmutable::parse('2026-11-19')))->toBe(0);
    expect(app(NpWorkflow::class)->pruneDocuments(CarbonImmutable::parse('2026-11-21')))->toBe(2)
        ->and(Storage::disk(NpDocument::DISK)->exists($kept->path))->toBeFalse()
        ->and($orphan->fresh())->toBeNull();
});

test('an interrupted .np submission is recovered by the sync sweep without opening a second request', function () {
    $domain = Domain::factory()->pending()->named('chandra', $this->tld)->create(['registrar_key' => 'np_manual']);
    app(NpWorkflow::class)->start($domain);
    $domain->forceFill(['registrar_meta' => ['submission' => now()->subHour()->toIso8601String()]])->save();

    app(SyncDomains::class)->handle();

    expect($domain->fresh())->status->toBe(DomainStatus::PendingRegistration)->needs_review->toBeFalse()
        ->and(NpRegistration::query()->where('domain_id', $domain->id)->count())->toBe(1);
});
