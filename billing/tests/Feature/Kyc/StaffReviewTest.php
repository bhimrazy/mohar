<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    Storage::disk(KycDocument::DISK)->put($this->document->back_path, Crypt::encryptString('back-bytes'));
    $this->link = fn (KycDocument $document, string $side = 'front'): string => URL::temporarySignedRoute('admin.kyc.image', now()->addMinutes(15), ['document' => $document->id, 'side' => $side]);
    $this->decide = fn (array $data, ?KycDocument $document = null) => $this->post(route('admin.kyc.decide', ($document ?? $this->document)->id), [
        'version' => DecideDocument::version(($document ?? $this->document)->fresh()),
        ...$data,
    ]);
});

test('the review permission is sensitive: kyc.* does not grant it, the exact key or * does', function () {
    expect(app(PermissionRegistry::class)->isSensitive('kyc.documents.review'))->toBeTrue();

    actingAsStaff(['kyc.*']);
    confirmPassword();
    $this->get(route('admin.kyc.index'))->assertForbidden();
    $this->get(route('admin.kyc.show', $this->client))->assertForbidden();
    $this->get(($this->link)($this->document))->assertForbidden();
    $this->post(route('admin.kyc.unlock', $this->client))->assertForbidden();
    ($this->decide)(['decision' => 'approve'])->assertForbidden();

    actingAsStaff(['kyc.documents.review']);
    $this->get(route('admin.kyc.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/kyc/index')->where('queue.data.0.client.id', $this->client->id)->where('queue.data.0.waiting', 1));
});

test('numbers and image links are shown only after a password step-up, which is audited', function () {
    actingAsStaff(['kyc.documents.review']);

    $response = $this->get(route('admin.kyc.show', $this->client))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlocked', false)->where('documents.0.number', null)->where('documents.0.images', []));
    expect($response->getContent())->not->toContain('27-01-74-04821')->not->toContain($this->document->front_path);

    $this->post(route('admin.kyc.unlock', $this->client))->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->post(route('admin.kyc.unlock', $this->client))->assertRedirect(route('admin.kyc.show', $this->client));
    $this->get(route('admin.kyc.show', $this->client))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlocked', true)->where('documents.0.number', '27-01-74-04821')->has('documents.0.images', 2));

    expect(ActivityLog::query()->where('event', 'kyc.documents.opened')->where('client_id', $this->client->id)->count())->toBe(1);
});

test('a signed image link streams the decrypted image, logs the view, and stops working after 15 minutes', function () {
    actingAsStaff(['kyc.documents.review']);
    confirmPassword();
    $url = ($this->link)($this->document, 'back');

    $this->get($url)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png')
        ->assertSee('back-bytes', false);
    $log = ActivityLog::query()->where('event', 'kyc.document.viewed')->sole();
    expect($log->properties)->toBe(['type' => 'citizenship', 'side' => 'back'])
        ->and(json_encode($log->toArray()))->not->toContain('04821');

    $this->get(str_replace('/back?', '/front?', $url))->assertForbidden();
    $this->travel(16)->minutes();
    confirmPassword();
    $this->get($url)->assertForbidden();
});

test('approving and rejecting need a recent password, are audited, and the client sees the reason', function () {
    $staff = actingAsStaff(['kyc.documents.review']);

    ($this->decide)(['decision' => 'approve'])->assertSessionHasErrors('password_confirmation');
    expect($this->document->fresh()->status)->toBe(DocumentStatus::Pending);

    confirmPassword();
    ($this->decide)(['decision' => 'reject'])->assertSessionHasErrors('reason');
    ($this->decide)(['decision' => 'reject', 'reason' => 'The back is blurred.'])->assertRedirect(route('admin.kyc.show', $this->client->id));

    expect($this->document->fresh())->status->toBe(DocumentStatus::Rejected)->rejection_reason->toBe('The back is blurred.')->decided_by->toBe($staff->id)
        ->and(ActivityLog::query()->where('event', 'kyc.document.rejected')->sole()->properties)->toBe(['type' => 'citizenship', 'reason' => 'The back is blurred.']);

    $nid = KycDocument::factory()->type(DocumentType::NationalId)->create(['client_id' => $this->client->id]);
    ($this->decide)(['decision' => 'approve'], $nid)->assertSessionHasNoErrors();
    expect($nid->fresh()->status)->toBe(DocumentStatus::Approved)
        ->and(ActivityLog::query()->where('event', 'kyc.document.approved')->count())->toBe(1);

    app(Settings::class)->set('kyc.enabled', true);
    actingAsClient($this->client);
    $this->get(route('client.kyc.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.current.reason', 'The back is blurred.')
        ->where('documents.1.current.status', 'approved')->where('status.value', 'rejected'));
});

test('a decision on a document that was replaced or changed after the page loaded fails', function () {
    actingAsStaff(['kyc.documents.review']);
    confirmPassword();
    $staleVersion = DecideDocument::version($this->document);

    $this->document->forceFill(['superseded_at' => now()])->save();
    $this->post(route('admin.kyc.decide', $this->document->id), ['decision' => 'approve', 'version' => $staleVersion])->assertSessionHasErrors('document');

    $other = KycDocument::factory()->create(['client_id' => $this->client->id]);
    $this->post(route('admin.kyc.decide', $other->id), ['decision' => 'approve', 'version' => $staleVersion])->assertSessionHasErrors('document');

    expect($other->fresh()->status)->toBe(DocumentStatus::Pending)
        ->and(ActivityLog::query()->where('event', 'like', 'kyc.document.%ed')->where('event', '!=', 'kyc.document.uploaded')->count())->toBe(0);
});
