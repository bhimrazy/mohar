<?php

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSummary;
use App\Events\Services\ServiceActivated;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Actions\PruneNotificationLogs;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\NotificationMailer;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;
use Tests\Fakes\Services\FakePriceBook;

require_once __DIR__.'/helpers.php';

it('sends the service password but stores only a redacted copy (criterion 9)', function () {
    Mail::fake();
    seedEmailTemplates();
    $priceBook = new FakePriceBook;
    $priceBook->products[9] = new ProductSummary(9, 'Starter Hosting', 'Hosting', 'shared_hosting', 'log', null, [], 'payment', null, false, true, null, true);
    app()->instance(PriceBook::class, $priceBook);
    fakeMergeFields([
        'service' => ['service_id' => 4, 'product_id' => 9, 'product_name' => 'Starter Hosting', 'domain' => 'example.com', 'username' => 'ann', 'nameservers' => ['ns1.example.net']],
        'service_credentials' => ['password' => 'S3cret!pass'],
    ]);
    $client = Client::factory()->create(['email' => 'owner@example.test']);

    app(SendEventEmails::class)->serviceActivated(new ServiceActivated(4, $client->id));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'S3cret!pass') && str_contains($mail->plainText, 'S3cret!pass'));
    $logged = EmailMessage::query()->where('template_key', 'service.welcome.shared_hosting')->sole();
    expect($logged->body_html)->not->toContain('S3cret')->toContain('••••')
        ->and($logged->body_text)->not->toContain('S3cret')->toContain('••••')
        ->and($logged->has_redactions)->toBeTrue();
});

it('marks a message failed after three transport failures and resends it as a new attempt (criterion 10)', function () {
    Mail::extend('failing', fn () => new class extends ArrayTransport
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('SMTP connection refused');
        }
    });
    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    $message = EmailMessage::factory()->create(['status' => EmailStatus::Queued, 'attempts' => 0, 'sent_at' => null]);
    $job = new SendEmailMessage($message->id);
    $settings = app(Settings::class);

    expect(fn () => $job->handle($settings))->toThrow(TransportException::class)
        ->and(fn () => $job->handle($settings))->toThrow(TransportException::class);
    $job->handle($settings);

    expect($message->fresh())->status->toBe(EmailStatus::Failed)->attempts->toBe(3)->failure_reason->toContain('SMTP connection refused');

    config(['mail.default' => 'array']);
    app(NotificationMailer::class)->resend($message->fresh());

    expect($message->fresh())->status->toBe(EmailStatus::Sent)->attempts->toBe(4)->failure_reason->toBeNull();
});

it('clears old email bodies but keeps the metadata', function () {
    $old = EmailMessage::factory()->create(['created_at' => now()->subDays(400)]);
    $recent = EmailMessage::factory()->create();

    app(PruneNotificationLogs::class)->handle(CarbonImmutable::today());

    expect($old->fresh())->body_html->toBeNull()->body_text->toBeNull()->body_purged_at->not->toBeNull()->subject->toBe($old->subject)
        ->and($recent->fresh()->body_html)->not->toBeNull();
});
