<?php

namespace App\Modules\Support\Services;

use Illuminate\Http\UploadedFile;

/**
 * A file on its way into a ticket, from a web upload or an inbound email.
 */
final readonly class IncomingFile
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
    ) {}

    public static function fromUpload(UploadedFile $file): self
    {
        return new self($file->getClientOriginalName(), (string) $file->getRealPath(), (int) $file->getSize());
    }

    /**
     * Write decoded email attachment content to a temporary file.
     */
    public static function fromContents(string $name, string $contents): self
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'support-inbound-');
        file_put_contents($path, $contents);

        return new self($name, $path, strlen($contents));
    }
}
