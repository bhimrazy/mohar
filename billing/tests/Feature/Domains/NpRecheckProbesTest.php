<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\NpWorkflow;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Re-check probes for #168 (abandoned .np requests), adopted as regression tests. B1 and B3 follow the revised rule
 * (CTO, 2026-10-20): an abandoned request is only listed for staff with what was paid; nothing is cancelled by the
 * system. B2 is as the reviewer wrote it.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake(NpDocument::DISK);
    CarbonImmutable::setTestNow('2026-10-21 10:00:00');
    [$this->user, $this->client] = actingAsClient();
    $this->tld = Tld::factory()->extension('.com.np')->priced('NPR', 150000)->create(['registrar_key' => 'np_manual']);
    $this->domain = Domain::factory()->pending()->named('asha', $this->tld)->create([
        'client_id' => $this->client->id, 'registrar_key' => 'np_manual', 'currency' => 'NPR', 'first_payment_amount' => 150000, 'provision_invoice_id' => 1247,
    ]);
    app(SubmitToRegistrar::class)->handle($this->domain);
    $this->prune = fn (string $day) => app(NpWorkflow::class)->pruneDocuments(CarbonImmutable::parse($day));
    $this->abandonedTodo = fn (): ?array => collect(app(Dashboard::class)->resolveTodos('admin', actingAsStaff(['domains.np.review'])))->firstWhere('key', 'domains.np_abandoned');
});

test('B1: a paid request waiting for staff review is neither cancelled nor listed as abandoned', function () {
    $this->post(route('client.domains.np.documents.store', $this->domain->id), ['applicant_type' => 'individual', 'kind' => 'identity', 'file' => fakePng('id.png', 5, 5)]);
    $this->post(route('client.domains.np.review', $this->domain->id))->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00')); // staff never reviewed it

    ($this->prune)('2027-02-01');

    expect($this->domain->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and(($this->abandonedTodo)())->toBeNull();
});

test('B1: a domain that is already active is never cancelled or listed as abandoned', function () {
    $this->domain->fresh()->forceFill(['status' => DomainStatus::Active])->save(); // staff registered it outside the workflow
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00'));

    ($this->prune)('2027-02-01');

    expect($this->domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and(($this->abandonedTodo)())->toBeNull();
});

test('B2: one request whose domain cannot be cancelled does not stop the daily deletion of documents', function () {
    $this->domain->fresh()->forceFill(['status' => DomainStatus::Grace])->save(); // Grace -> Cancelled is not a legal transition
    $other = Domain::factory()->pending()->named('bikash', $this->tld)->create(['registrar_key' => 'np_manual', 'status' => DomainStatus::Cancelled]);
    $due = app(NpWorkflow::class)->start($other)->documents()->create(['kind' => 'identity', 'path' => 'np-documents/x', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 1, 'delete_after' => '2026-11-01']);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00'));

    ($this->prune)('2027-02-01');

    expect($due->fresh())->toBeNull();
});

test('B3: an abandoned paid request is listed for staff with its domain, client, invoice and amount, and not cancelled', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00'));

    ($this->prune)('2027-02-01');
    $todo = ($this->abandonedTodo)();

    expect($this->domain->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and($todo['count'])->toBe(1)
        ->and($todo['label'])->toContain('asha.com.np')->toContain($this->client->display_name)->toContain('1,500.00 paid')->toContain('order invoice')
        ->and($todo['href'])->toBe(route('admin.domains.show', $this->domain->id));
});

test('the abandoned-request check is idempotent: repeated runs change and log nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00'));

    ($this->prune)('2027-02-01');
    $entries = ActivityLog::query()->count();
    ($this->prune)('2027-02-01');
    ($this->prune)('2027-02-02');

    expect($this->domain->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and(ActivityLog::query()->count())->toBe($entries);
});
