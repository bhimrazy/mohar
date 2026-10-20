<?php

namespace App\Modules\Migration\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Stream wrapper behind EncryptedFile::open(): reads one encrypted line at a time from the source stream and serves
 * its plaintext, so fgetcsv() sees an ordinary file.
 *
 * @internal
 */
final class DecryptingStream
{
    /** @var resource|null set by PHP from the stream context */
    public $context;

    /** @var resource */
    private $source;

    private string $buffer = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $source = stream_context_get_options($this->context)['migration-decrypt']['source'] ?? null;

        if (! is_resource($source)) {
            return false;
        }

        $this->source = $source;

        return true;
    }

    public function stream_read(int $count): string
    {
        while (strlen($this->buffer) < $count && ($line = fgets($this->source)) !== false) {
            if (($line = rtrim($line, "\n")) !== '') {
                $this->buffer .= Crypt::decryptString($line);
            }
        }

        $read = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, strlen($read));

        return $read;
    }

    public function stream_eof(): bool
    {
        return $this->buffer === '' && feof($this->source);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        fclose($this->source);
    }
}
