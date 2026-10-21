<?php

use App\Models\User;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Enums\KycStatus;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycVerification;
use App\Modules\Kyc\Services\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    [$this->user, $this->client] = actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
});

/**
 * An upload whose MIME type comes from its bytes (finfo), as in production; UploadedFile::fake() trusts the name.
 */
function kycUpload(string $name, ?string $bytes = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kyc');
    file_put_contents($path, $bytes ?? (string) fakePng($name, 8, 8)->get());

    return new UploadedFile($path, $name, null, null, true);
}

function kycSubmit(DocumentType $type, string $number = '27-01-74-01234', array $overrides = []): TestResponse
{
    return test()->post(route('client.kyc.documents.store'), [
        'type' => $type->value,
        'number' => $number,
        'front' => kycUpload('front.png'),
        'back' => kycUpload('back.png'),
        ...$overrides,
    ]);
}

test('with verification off (the default) there is no banner and the page answers 404', function () {
    app(Settings::class)->set('kyc.enabled', false);

    expect(config('kyc.enabled'))->toBeFalse();
    $this->get(route('client.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('kyc', null));
    $this->get(route('client.kyc.show'))->assertNotFound();
    kycSubmit(DocumentType::Citizenship)->assertNotFound();
});

test('an unverified client sees the banner on client-area pages, and it changes to pending review after two types', function () {
    $this->get(route('client.dashboard'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kyc.status', 'not_started')->where('kyc.href', route('client.kyc.show')));

    kycSubmit(DocumentType::Citizenship)->assertRedirect(route('client.kyc.show'));
    kycSubmit(DocumentType::DrivingLicence, 'DL 01-06-123456')->assertRedirect(route('client.kyc.show'));

    $this->get(route('client.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('kyc.status', 'pending_review'));
});

test('the banner is not shared on admin pages', function () {
    actingAsStaff();

    $this->get(route('admin.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('kyc', null));
});

test('a stored document is encrypted at rest, and the page shows neither the number, the path nor the bytes', function () {
    kycSubmit(DocumentType::Citizenship, '27-01-74-01234')->assertSessionHasNoErrors();
    $document = KycDocument::query()->sole();
    $raw = (string) Storage::disk(KycDocument::DISK)->get($document->front_path);

    expect($raw)->not->toContain('PNG')
        ->and(Crypt::decryptString($raw))->toStartWith("\x89PNG")
        ->and(DB::table('kyc_documents')->value('number'))->not->toContain('01234')
        ->and($document->number)->toBe('27-01-74-01234')
        ->and($document->back_path)->not->toBeNull();

    $response = $this->get(route('client.kyc.show'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('client/kyc/show')
            ->where('documents.0.current.number', '••••1234')
            ->where('documents.0.current.status', 'pending'));
    expect($response->getContent())->not->toContain('27-01-74-01234')->not->toContain($document->front_path);
});

test('the image type is read from the bytes: an SVG renamed .png and a PDF are refused', function (string $bytes) {
    kycSubmit(DocumentType::Citizenship, overrides: ['front' => kycUpload('front.png', $bytes)])
        ->assertSessionHasErrors('front');

    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
})->with([
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'pdf' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF",
]);

test('an image over 5 MB is refused, and the back image is required', function () {
    kycSubmit(DocumentType::Citizenship, overrides: ['front' => fakePng('big.png', 1400, 1400, noise: true)])
        ->assertSessionHasErrors('front');
    kycSubmit(DocumentType::Citizenship, overrides: ['back' => null])->assertSessionHasErrors('back');

    expect(KycDocument::query()->count())->toBe(0);
});

test('replacing a rejected document deletes the old files, and an approved document cannot be replaced', function () {
    kycSubmit(DocumentType::Citizenship);
    $old = KycDocument::query()->sole();
    $old->forceFill(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'The photo is blurred.'])->save();

    $this->get(route('client.kyc.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.current.reason', 'The photo is blurred.')->where('status.value', 'rejected'));

    kycSubmit(DocumentType::Citizenship, '27-01-74-05555')->assertSessionHasNoErrors();

    $current = KycDocument::query()->sole();
    expect($current->id)->not->toBe($old->id)
        ->and(Storage::disk(KycDocument::DISK)->exists($old->front_path))->toBeFalse()
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toHaveCount(2);

    $current->forceFill(['status' => DocumentStatus::Approved])->save();
    kycSubmit(DocumentType::Citizenship, '99-99')->assertSessionHasErrors('type');
    expect(KycDocument::query()->sole()->id)->toBe($current->id)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toHaveCount(2);
});

test('a client sees and changes only the current account, and a login without the profile area is refused', function () {
    $other = Client::factory()->create();
    $theirs = KycDocument::factory()->create(['client_id' => $other->id, 'number' => 'THEIRS-9876']);

    $this->get(route('client.kyc.show'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.current', null));
    kycSubmit(DocumentType::Citizenship, overrides: ['client_id' => $other->id]);
    expect(KycDocument::query()->where('client_id', $other->id)->sole()->id)->toBe($theirs->id)
        ->and(KycDocument::query()->where('client_id', $this->client->id)->count())->toBe(1);

    $member = User::factory()->create();
    $this->client->users()->attach($member, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($member);

    $this->get(route('client.kyc.show'))->assertForbidden();
    kycSubmit(DocumentType::NationalId)->assertForbidden();
});

test('the email step counts an address set by staff or an import, or verified by an owner login with that address', function () {
    $kyc = app(KycVerification::class);
    $this->client->forceFill(['email_source' => EmailSource::Client])->save();
    expect($kyc->emailProved($this->client))->toBeTrue();

    $this->user->forceFill(['email_verified_at' => null])->save();
    expect($kyc->emailProved($this->client->fresh()))->toBeFalse();

    $member = User::factory()->create(['email' => 'member@example.np']);
    $this->client->users()->attach($member, ['is_owner' => false]);
    $this->client->setEmailBy(EmailSource::Client, 'member@example.np')->save();
    expect($kyc->emailProved($this->client->fresh()))->toBeFalse();

    $this->client->forceFill(['email_source' => EmailSource::Staff])->save();
    expect($kyc->emailProved($this->client->fresh()))->toBeTrue();
});

test('the phone step saves Nepal numbers with +977 and refuses an invalid number with the reason', function () {
    $this->put(route('client.kyc.phone'), ['phone' => '9841-234567'])->assertSessionHasNoErrors();
    expect($this->client->fresh()->phone)->toBe('+977 9841234567');

    $this->put(route('client.kyc.phone'), ['phone' => '12345'])->assertSessionHasErrors(['phone' => 'Enter a valid phone number, for example +977 9841234567.']);
    expect($this->client->fresh()->phone)->toBe('+977 9841234567');

    expect(PhoneNumber::normalise('01-4412345', '+977'))->toBe('+977 14412345')
        ->and(PhoneNumber::normalise('+977 984 1234567', '+977'))->toBe('+977 9841234567')
        ->and(PhoneNumber::normalise('+91 98765 43210', '+977'))->toBe('+919876543210')
        ->and(PhoneNumber::normalise('9841<script>', '+977'))->toBeNull();
});

test('verified needs email, phone and two different approved types; one approved type is not enough', function () {
    $kyc = app(KycVerification::class);
    $this->client->forceFill(['phone' => '+977 9841234567'])->save();
    KycDocument::factory()->approved()->create(['client_id' => $this->client->id]);
    expect($kyc->status($this->client))->toBe(KycStatus::InProgress);

    KycDocument::factory()->approved()->type(DocumentType::Citizenship)->create(['client_id' => $this->client->id, 'superseded_at' => now()]);
    expect($kyc->status($this->client))->toBe(KycStatus::InProgress);

    KycDocument::factory()->approved()->type(DocumentType::NationalId)->create(['client_id' => $this->client->id]);
    expect($kyc->status($this->client))->toBe(KycStatus::Verified);
    $this->get(route('client.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('kyc', null));

    $this->client->forceFill(['phone' => null])->save();
    expect($kyc->status($this->client))->toBe(KycStatus::InProgress);
});
