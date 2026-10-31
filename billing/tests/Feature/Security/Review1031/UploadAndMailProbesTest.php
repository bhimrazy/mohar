<?php

/*
 * Security round 2026-10-31, file-upload and mail-server tracks.
 * U1–U3 (expected to hold): the upload policy is structural. Only extensions in AttachmentStore::MIME_MAP can pass,
 * whatever staff type into the allow-list, the content must match, and a PDF is never served inline.
 * M1 (expected to hold): a metadata or link-local SMTP host is refused at save, also by a partial save that leaves out
 * `mail.default` (MailRelayHost reads it from the request; the required mailer field refuses the partial save).
 */

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Models\TicketAttachment;
use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Mail\SmtpStub;

require_once dirname(__DIR__, 2).'/Support/helpers.php';

beforeEach(fn () => Storage::fake('objects'));

test('U1 an extension staff add to the client allow-list still needs a content type the store knows', function (string $name, string $content) {
    app(Settings::class)->set('support.attachment_extensions', 'png,txt,pdf,html,svg,xml,xhtml,htm,shtml');
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.reply', $ticket->number), ['body' => 'File', 'attachments' => [UploadedFile::fake()->createWithContent($name, $content)]])
        ->assertSessionHasErrors('attachments.0');
    expect(TicketAttachment::query()->count())->toBe(0);
})->with([
    'xhtml' => ['page.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>'],
    'xml' => ['data.xml', '<?xml version="1.0"?><x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script>'],
    'shtml' => ['page.shtml', '<html><script>alert(1)</script></html>'],
    'html in txt' => ['notes.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
    'svg in png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
]);

test('U2 a stored name keeps no path and a PDF is never served inline', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.reply', $ticket->number), ['body' => 'File', 'attachments' => [
        UploadedFile::fake()->createWithContent('../../../etc/passwd.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
    ]])->assertSessionHasNoErrors();
    $attachment = TicketAttachment::query()->sole();

    expect($attachment->original_name)->toBe('passwd.pdf')
        ->and($attachment->path)->toStartWith("support/tickets/{$ticket->id}/")->not->toContain('..');

    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id, 'inline' => 1]))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename=passwd.pdf');
});

test('U3 another account\'s attachment id on my ticket number is 404', function () {
    [, $mine] = actingAsClient();
    $myTicket = clientTicket($mine);
    $other = Client::factory()->create();
    $theirs = clientTicket($other);
    $theirFile = TicketAttachment::query()->create(['ticket_id' => $theirs->id, 'disk' => 'objects', 'path' => "support/tickets/{$theirs->id}/x.txt", 'original_name' => 'x.txt', 'stored_name' => 'x.txt', 'mime' => 'text/plain', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)]);
    Storage::disk('objects')->put($theirFile->path, 'secret');

    $this->get(route('client.support.tickets.attachments.show', [$myTicket->number, $theirFile->id]))->assertNotFound();
});

test('M1 a partial mail-server save cannot store a metadata host while SMTP is the active mailer', function () {
    app()->instance(OutboundAddressGuard::class, SmtpStub::guard());
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $full = ['mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['host' => 'smtp.example.com.np', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => '']]]];
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), $full)->assertSessionHasNoErrors();

    // The full form refuses it (holds).
    $full['mail']['mailers']['smtp']['host'] = '169.254.169.254';
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), $full)->assertSessionHasErrors('mail.mailers.smtp.host');

    // A save without mail.default, which MailRelayHost reads, is refused as a whole.
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), ['mail' => ['mailers' => ['smtp' => ['host' => 'metadata.example.np', 'port' => 80]]]])
        ->assertSessionHasErrors('mail.default');
    expect(app(Settings::class)->get('mail.mailers.smtp.host'))->toBe('smtp.example.com.np');
});
