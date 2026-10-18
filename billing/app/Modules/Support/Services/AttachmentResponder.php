<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a ticket attachment after the caller authorised access (spec §4.3): always
 * "attachment" disposition except images explicitly requested inline, nosniff, sandboxed CSP.
 */
class AttachmentResponder
{
    public function respond(TicketAttachment $attachment, bool $inline = false): StreamedResponse
    {
        abort_if($attachment->pruned_at !== null, 410, __('This file was removed after the ticket was closed.'));
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];

        $disposition = $inline && $attachment->isImage() ? 'inline' : 'attachment';

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, $headers, $disposition);
    }
}
