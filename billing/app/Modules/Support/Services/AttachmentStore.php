<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Models\TicketMessage;
use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Attachment rules (spec §4.3): extension allow-lists, hard deny list, content-detected MIME that must
 * match the extension, size/count limits, sanitised names and random storage paths on the private
 * "objects" disk.
 */
class AttachmentStore
{
    public const DISK = 'objects';

    /** Extension => MIME types finfo may detect for genuine files of that type. */
    private const MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'log' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
    ];

    public function __construct(private SupportSettings $settings) {}

    public function maxBytes(): int
    {
        return max(1, $this->settings->int('attachment_max_mb', 10)) * 1024 * 1024;
    }

    public function maxFiles(): int
    {
        return max(1, $this->settings->int('attachment_max_files', 5));
    }

    /**
     * Why the file is not acceptable, or null when it is.
     */
    public function rejectionReason(IncomingFile $file, bool $staff): ?string
    {
        $segments = explode('.', strtolower($file->name));
        $extension = count($segments) > 1 ? (string) end($segments) : '';

        foreach (array_slice($segments, 1) as $segment) {
            if (in_array($segment, SupportSettings::HARD_DENY, true)) {
                return __('Files of type .:type are not allowed.', ['type' => $segment]);
            }
        }

        if ($extension === '' || ! in_array($extension, $this->settings->allowedExtensions($staff), true)) {
            return __('Allowed file types: :types.', ['types' => implode(', ', $this->settings->allowedExtensions($staff))]);
        }

        if ($file->size > $this->maxBytes()) {
            return __('Files may be at most :size MB.', ['size' => $this->settings->int('attachment_max_mb', 10)]);
        }

        $mime = $this->detectMime($file->path);
        $expected = self::MIME_MAP[$extension] ?? null;

        if ($expected === null || ! in_array($mime, $expected, true)) {
            return __('The file content does not match its .:type extension.', ['type' => $extension]);
        }

        return null;
    }

    public function store(Ticket $ticket, ?TicketMessage $message, IncomingFile $file, int $index = 1): TicketAttachment
    {
        $original = $this->sanitizeName($file->name, $index);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $stored = Str::random(40).($extension !== '' ? '.'.$extension : '');
        $path = "support/tickets/{$ticket->id}/{$stored}";
        $stream = fopen($file->path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to read the uploaded file {$file->name}.");
        }

        Storage::disk(self::DISK)->writeStream($path, $stream, ['visibility' => 'private']);
        fclose($stream);

        return TicketAttachment::query()->create([
            'ticket_id' => $ticket->id,
            'message_id' => $message?->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $original,
            'stored_name' => $stored,
            'mime' => $this->detectMime($file->path),
            'size_bytes' => $file->size,
            'sha256' => (string) hash_file('sha256', $file->path),
        ]);
    }

    /**
     * Keep [A-Za-z0-9._-] only; empty names become attachment_{n}.
     */
    public function sanitizeName(string $name, int $index = 1): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]/', '', str_replace(' ', '_', basename($name)));
        $clean = ltrim($clean, '.');

        if ($clean === '' || pathinfo($clean, PATHINFO_FILENAME) === '') {
            $extension = pathinfo($clean, PATHINFO_EXTENSION);

            return 'attachment_'.$index.($extension !== '' ? '.'.$extension : '');
        }

        return mb_substr($clean, -200);
    }

    private function detectMime(string $path): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    }
}
