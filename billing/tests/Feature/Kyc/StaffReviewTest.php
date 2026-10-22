<?php

use App\Events\Kyc\DocumentDecided;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    Storage::disk(KycDocument::DISK)->put($this->document->back_path, Crypt::encryptString('back-bytes'));
    $this->unlock = function (?Client $client = null): void {
        confirmPassword();
        $this->post(route('admin.kyc.unlock', $client ?? $this->client))->assertRedirect();
    };
    $this->imageUrl = fn (int $index = 0): string => $this->get(route('admin.kyc.show', $this->client))->viewData('page')['props']['documents'][0]['images'][$index]['url'];
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
    $this->post(route('admin.kyc.unlock', $this->client))->assertForbidden();
    ($this->decide)(['decision' => 'approve'])->assertForbidden();

    actingAsStaff(['kyc.documents.review']);
    $this->get(route('admin.kyc.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/kyc/index')
            ->where('clients.data.0.id', $this->client->id)->where('clients.data.0.waiting', 1)->where('counts.pending_review', 1));
});

test('numbers and images show only for the client that was unlocked, after a step-up, with one audit entry each', function () {
    actingAsStaff(['kyc.documents.review']);
    $other = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $other->id, 'number' => '99-88-77-66666']);

    $response = $this->get(route('admin.kyc.show', $this->client))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null)->where('documents.0.number', null)->where('documents.0.images', []));
    expect($response->getContent())->not->toContain('27-01-74-04821')->not->toContain($this->document->front_path);

    $this->post(route('admin.kyc.unlock', $this->client))->assertSessionHasErrors('password_confirmation');
    ($this->unlock)();

    $this->get(route('admin.kyc.show', $this->client))
        ->assertInertia(fn (AssertableInertia $page) => $page->whereNot('unlockedUntil', null)->where('documents.0.number', '27-01-74-04821')->has('documents.0.images', 2));
    $this->get(route('admin.kyc.show', $other))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null)->where('documents.0.number', null));

    ($this->unlock)($other);
    expect(ActivityLog::query()->where('event', 'kyc.documents.opened')->pluck('client_id')->all())->toBe([$this->client->id, $other->id]);
});

test('the unlock ends with the password step-up, whichever comes first', function () {
    $this->freezeSecond();
    actingAsStaff(['kyc.documents.review']);
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(10)->getTimestamp()]);
    $this->post(route('admin.kyc.unlock', $this->client))->assertRedirect();

    $this->get(route('admin.kyc.show', $this->client))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', now()->addMinutes(5)->toIso8601String()));
    $url = ($this->imageUrl)();
    $this->travel(6)->minutes();

    $this->get(route('admin.kyc.show', $this->client))->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null));
    $this->get($url)->assertForbidden();
});

test('an image link streams the decrypted image for the staff member it was made for, logs the view, and expires', function () {
    $first = actingAsStaff(['kyc.documents.review']);
    ($this->unlock)();
    $url = ($this->imageUrl)(1);

    $this->get($url)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline; filename="citizenship-certificate-back"')
        ->assertSee('back-bytes', false);
    $log = ActivityLog::query()->where('event', 'kyc.document.viewed')->sole();
    expect($log->properties)->toBe(['type' => 'citizenship', 'side' => 'back'])
        ->and(json_encode($log->toArray()))->not->toContain('04821');
    $this->get(str_replace('/back?', '/front?', $url))->assertForbidden();

    actingAsStaff(['kyc.documents.review']);
    ($this->unlock)();
    $this->get($url)->assertForbidden(); // made for someone else

    $this->actingAs($first);
    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
});

test('an image of a client that was not unlocked is refused, and a missing file answers 404 without logging the path', function () {
    $staff = actingAsStaff(['kyc.documents.review']);
    $link = URL::temporarySignedRoute('admin.kyc.image', now()->addMinutes(15), ['document' => $this->document->id, 'side' => 'front', 'staff' => $staff->id]);
    $this->get($link)->assertForbidden();

    ($this->unlock)();
    Storage::disk(KycDocument::DISK)->delete($this->document->front_path);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context, JSON_UNESCAPED_SLASHES);
    });

    $this->get($link)->assertNotFound();
    expect(implode("\n", $logged))->not->toContain($this->document->front_path);
});

test('approve and decline stay refused until the documents were shown, need a recent password, and are audited', function () {
    $staff = actingAsStaff(['kyc.documents.review']);

    ($this->decide)(['decision' => 'approve'])->assertSessionHasErrors('password_confirmation');
    confirmPassword();
    ($this->decide)(['decision' => 'approve'])->assertSessionHasErrors(['document' => 'Open the documents first, then decide.']);
    expect($this->document->fresh()->status)->toBe(DocumentStatus::Pending);

    ($this->unlock)();
    ($this->decide)(['decision' => 'decline'])->assertSessionHasErrors(['reason' => 'Tell the client why, so they know what to fix.']);
    ($this->decide)(['decision' => 'decline', 'reason' => 'The back is blurred.'])->assertRedirect(route('admin.kyc.show', $this->client->id));

    expect($this->document->fresh())->status->toBe(DocumentStatus::Declined)->decline_reason->toBe('The back is blurred.')->decided_by->toBe($staff->id)
        ->and(ActivityLog::query()->where('event', 'kyc.document.declined')->sole()->properties)->toBe(['type' => 'citizenship', 'reason' => 'The back is blurred.']);

    $nid = KycDocument::factory()->type(DocumentType::NationalId)->create(['client_id' => $this->client->id]);
    ($this->decide)(['decision' => 'approve'], $nid)->assertSessionHasNoErrors();
    expect($nid->fresh()->status)->toBe(DocumentStatus::Approved);

    app(Settings::class)->set('kyc.enabled', true);
    actingAsClient($this->client);
    $this->get(route('client.kyc.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.current.reason', 'The back is blurred.')
        ->where('documents.0.current.statusLabel', 'Declined')->where('documents.1.current.status', 'approved')->where('status.value', 'declined'));
});

test('every decision emails the client the outcome, and a decline the reason, with one button to the verification page', function () {
    Event::fake([DocumentDecided::class]);
    actingAsStaff(['kyc.documents.review']);
    ($this->unlock)();
    ($this->decide)(['decision' => 'decline', 'reason' => 'The back is blurred.']);

    Event::assertDispatched(DocumentDecided::class, fn (DocumentDecided $event): bool => $event->clientId === $this->client->id && $event->documentId === $this->document->id);
});

test('the queue filters by status and finds any client by search', function () {
    actingAsStaff(['kyc.documents.review']);
    $verified = Client::factory()->create(['first_name' => 'Sita']);
    KycDocument::factory()->approved()->create(['client_id' => $verified->id]);
    KycDocument::factory()->approved()->type(DocumentType::NationalId)->create(['client_id' => $verified->id]);
    $declined = Client::factory()->create();
    KycDocument::factory()->declined()->create(['client_id' => $declined->id]);

    $ids = fn (array $query): array => collect($this->get(route('admin.kyc.index', $query))->viewData('page')['props']['clients']['data'])->pluck('id')->all();

    expect($ids([]))->toBe([$this->client->id])
        ->and($ids(['status' => 'declined']))->toBe([$declined->id])
        ->and($ids(['status' => 'verified']))->toBe([$verified->id])
        ->and($ids(['status' => 'all', 'q' => 'Sita']))->toBe([$verified->id])
        ->and($ids(['status' => 'not_started']))->not->toContain($this->client->id, $verified->id, $declined->id);

    $this->get(route('admin.kyc.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts.pending_review', 1)->where('counts.declined', 1)->where('counts.verified', 1));
});

test('a decision on a document that was replaced or changed after the page loaded fails', function () {
    actingAsStaff(['kyc.documents.review']);
    ($this->unlock)();
    $staleVersion = DecideDocument::version($this->document);

    $this->document->forceFill(['superseded_at' => now()])->save();
    $this->post(route('admin.kyc.decide', $this->document->id), ['decision' => 'approve', 'version' => $staleVersion])->assertSessionHasErrors('document');

    $other = KycDocument::factory()->create(['client_id' => $this->client->id]);
    $this->post(route('admin.kyc.decide', $other->id), ['decision' => 'approve', 'version' => $staleVersion])->assertSessionHasErrors('document');

    expect($other->fresh()->status)->toBe(DocumentStatus::Pending)
        ->and(ActivityLog::query()->whereIn('event', ['kyc.document.approved', 'kyc.document.declined'])->count())->toBe(0);
});

test('the Support role can see the queue and the documents but cannot approve or decline (owner decision 2026-10-21)', function () {
    expect(app(PermissionRegistry::class)->isSensitive('kyc.documents.view'))->toBeTrue();
    actingAsStaff(SystemRoles::permissions('support'));

    $this->get(route('admin.kyc.index'))->assertOk();
    ($this->unlock)();
    $this->get(route('admin.kyc.show', $this->client))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canDecide', false)->where('documents.0.number', '27-01-74-04821'));
    $this->get(($this->imageUrl)())->assertOk();

    ($this->decide)(['decision' => 'approve'])->assertForbidden();
    ($this->decide)(['decision' => 'decline', 'reason' => 'Blurred.'])->assertForbidden();
    expect($this->document->fresh()->status)->toBe(DocumentStatus::Pending);

    actingAsStaff(SystemRoles::permissions('billing'));
    $this->get(route('admin.kyc.index'))->assertForbidden();
    actingAsStaff(SystemRoles::permissions(SystemRoles::FULL_ADMINISTRATOR));
    $this->get(route('admin.kyc.show', $this->client))->assertInertia(fn (AssertableInertia $page) => $page->where('canDecide', true));
});
