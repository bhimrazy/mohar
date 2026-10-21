<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Notifications\Models\EmailMessage;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Mail::fake();
    seedEmailTemplates();
    app(Settings::class)->set('kyc.enabled', true);
    $this->client = Client::factory()->create(['email' => 'owner@example.test', 'first_name' => 'Olive']);
    $this->staff = User::factory()->staff(['kyc.documents.review'])->create();
    $this->decide = fn (KycDocument $document, bool $approve, ?string $reason = null) => app(DecideDocument::class)
        ->handle($document->id, DecideDocument::version($document), $approve, $reason, $this->staff);
});

it('emails the client a declined document with the reason and one button to the verification page', function () {
    $document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);

    ($this->decide)($document, false, 'The back is blurred, so the issue date cannot be read.');

    $message = EmailMessage::query()->where('template_key', 'kyc.document_decided')->sole();
    expect($message->subject)->toBe('Your Citizenship certificate was declined')
        ->and($message->to)->toBe(['owner@example.test'])
        ->and($message->body_html)->toContain('The back is blurred, so the issue date cannot be read.')->toContain('Upload a new photo')
        ->toContain(route('client.kyc.show'))->not->toContain('27-01-74-04821')
        ->and($message->body_text)->toContain('Declined');
});

it('emails an approval, and says when the client is now verified', function () {
    $this->client->forceFill(['phone' => '+977 9841234567'])->save();
    User::factory()->create(['email' => 'owner@example.test'])->clients()->attach($this->client, ['is_owner' => true]);
    KycDocument::factory()->approved()->type(DocumentType::NationalId)->create(['client_id' => $this->client->id]);
    $document = KycDocument::factory()->create(['client_id' => $this->client->id]);

    ($this->decide)($document, true);

    $message = EmailMessage::query()->where('template_key', 'kyc.document_decided')->sole();
    expect($message->subject)->toBe('Your Citizenship certificate is approved')
        ->and($message->body_html)->toContain('Your identity is now verified')->toContain('View your verification');
});

it('sends no decision email while identity verification is off, because its link would not open', function () {
    app(Settings::class)->set('kyc.enabled', false);
    $document = KycDocument::factory()->create(['client_id' => $this->client->id]);

    ($this->decide)($document, false, 'The back is blurred.');

    expect($document->fresh()->decline_reason)->toBe('The back is blurred.')
        ->and(EmailMessage::query()->where('template_key', 'kyc.document_decided')->count())->toBe(0);
});
