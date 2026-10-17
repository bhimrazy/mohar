<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\IncomingFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

function pngUpload(string $name = 'screen.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode(TINY_PNG));
}

beforeEach(function () {
    Storage::fake('objects');
});

test('client attachments are stored privately under random names with sanitised originals', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.reply', $ticket->number), [
        'body' => 'Screenshot attached',
        'attachments' => [pngUpload('my screen (1).png')],
    ])->assertRedirect();

    $attachment = TicketAttachment::query()->sole();
    expect($attachment)->original_name->toBe('my_screen_1.png')->mime->toBe('image/png')
        ->disk->toBe('objects')->sha256->toHaveLength(64)
        ->and($attachment->stored_name)->not->toContain('screen')
        ->and($attachment->path)->toStartWith("support/tickets/{$ticket->id}/");
    Storage::disk('objects')->assertExists($attachment->path);
});

test('double extensions and content that does not match the extension are rejected', function (string $name, string $content) {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.reply', $ticket->number), [
        'body' => 'File',
        'attachments' => [UploadedFile::fake()->createWithContent($name, $content)],
    ])->assertSessionHasErrors('attachments.0');

    expect(TicketAttachment::query()->count())->toBe(0);
})->with([
    'php disguised as pdf' => ['invoice.pdf.php', '%PDF-1.4 <?php echo 1;'],
    'html disguised as png' => ['image.png', '<html><body><script>alert(1)</script></body></html>'],
    'svg is hard denied' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
    'extension not allowed' => ['notes.docx', 'PK'],
]);

test('files over the size limit are rejected with 422', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->postJson(route('client.support.tickets.reply', $ticket->number), [
        'body' => 'Big file',
        'attachments' => [UploadedFile::fake()->create('dump.pdf', 11 * 1024, 'application/pdf')],
    ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
});

test('the number of files per message is limited', function () {
    app(Settings::class)->set('support.attachment_max_files', 2);
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.reply', $ticket->number), [
        'body' => 'Many',
        'attachments' => [pngUpload('a.png'), pngUpload('b.png'), pngUpload('c.png')],
    ])->assertSessionHasErrors('attachments');
});

test('staff have a wider allow-list than clients', function () {
    $store = app(AttachmentStore::class);
    $path = (string) tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "a,b\n1,2\n");
    $csv = new IncomingFile('report.csv', $path, 8);

    expect($store->rejectionReason($csv, staff: true))->toBeNull()
        ->and($store->rejectionReason($csv, staff: false))->not->toBeNull();
});

test('downloads are authorised per audience and sent as nosniff attachments', function () {
    [, $client] = actingAsClient();
    $department = Department::factory()->create();
    $ticket = clientTicket($client, $department);
    $this->post(route('client.support.tickets.reply', $ticket->number), ['body' => 'pic', 'attachments' => [pngUpload()]]);
    $attachment = TicketAttachment::query()->sole();

    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('screen.png');

    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id, 'inline' => 1]))
        ->assertOk()->assertHeader('Content-Disposition', 'inline; filename=screen.png');

    actingAsOwnerOf(Client::factory()->create());
    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id]))->assertNotFound();

    auth()->logout();
    $this->get(route('support.tickets.attachments.show', [$ticket->number, $attachment->id, 'key' => $ticket->access_key]))->assertOk();
    $this->get(route('support.tickets.attachments.show', [$ticket->number, $attachment->id, 'key' => 'nope']))->assertNotFound();

    supportStaff([$department]);
    $this->get(route('admin.support.attachments.show', $attachment))->assertOk()->assertDownload('screen.png');

    supportStaff([Department::factory()->create()]);
    $this->get(route('admin.support.attachments.show', $attachment))->assertForbidden();
});

test('attachments on internal notes are never served to clients', function () {
    [, $client] = actingAsClient();
    $department = Department::factory()->create();
    $ticket = clientTicket($client, $department);
    $staff = User::factory()->staff()->create();
    app(AddNote::class)->handle($ticket, $staff, 'internal log', [IncomingFile::fromContents('server.log', "error\n")]);
    $attachment = TicketAttachment::query()->sole();

    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id]))->assertNotFound();
});

test('pruned attachments answer 410', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);
    $this->post(route('client.support.tickets.reply', $ticket->number), ['body' => 'pic', 'attachments' => [pngUpload()]]);
    $attachment = TicketAttachment::query()->sole();
    $attachment->forceFill(['pruned_at' => now()])->save();

    $this->get(route('client.support.tickets.attachments.show', [$ticket->number, $attachment->id]))->assertStatus(410);
});
