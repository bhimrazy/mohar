<?php

namespace App\Modules\Migration\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Uploaded files on the objects disk, encrypted with the app key before they leave the request (client-import spec
 * §5.1). The file is cut into 64 KB chunks, each encrypted on its own line, so memory stays flat both ways and
 * APP_PREVIOUS_KEYS keeps old uploads readable after a key rotation. open() returns a plain read stream for fgetcsv().
 */
final class EncryptedFile
{
    private const CHUNK_BYTES = 65536;

    private const PROTOCOL = 'migration-decrypt';

    /**
     * Encrypt a local file into the objects disk at $key, without its UTF-8 byte-order mark.
     */
    public static function store(string $localPath, string $key): void
    {
        $in = fopen($localPath, 'rb') ?: throw new RuntimeException('The uploaded file could not be read.');
        // Only ciphertext ever reaches this buffer, so spilling it to a temporary file is safe.
        $out = fopen('php://temp/maxmemory:'.(4 * self::CHUNK_BYTES), 'w+b') ?: throw new RuntimeException('No buffer for the upload.');

        fseek($in, fread($in, strlen(CsvFile::BOM)) === CsvFile::BOM ? strlen(CsvFile::BOM) : 0);

        while (($chunk = fread($in, self::CHUNK_BYTES)) !== false && $chunk !== '') {
            fwrite($out, Crypt::encryptString($chunk)."\n");
        }

        fclose($in);
        rewind($out);
        Storage::disk('objects')->writeStream($key, $out) ?: throw new RuntimeException('The upload could not be stored.');
        fclose($out);
    }

    /**
     * A read stream of the decrypted file.
     *
     * @return resource
     */
    public static function open(string $key)
    {
        $source = Storage::disk('objects')->readStream($key) ?? throw new RuntimeException('The uploaded file is no longer stored.');

        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, DecryptingStream::class);
        }

        return fopen(self::PROTOCOL.'://file', 'rb', false, stream_context_create([self::PROTOCOL => ['source' => $source]]))
            ?: throw new RuntimeException('The uploaded file could not be opened.');
    }
}
