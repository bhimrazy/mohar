<?php

namespace App\Support\Security;

/**
 * Files on a storage disk whose whole content is one Laravel encrypter payload, referenced by path columns of a
 * table. `security:reencrypt` writes each re-encrypted file under a new random name in the same directory, switches
 * the row to it with a compare-and-set on the old path, and only then deletes the old file, so a row that changed or
 * was deleted meanwhile never points at a missing file and a deleted row's file is not brought back.
 */
final readonly class EncryptedFiles
{
    /**
     * @param  list<string>  $pathColumns
     */
    public function __construct(
        public string $table,
        public array $pathColumns,
        public string $disk,
        public string $keyColumn = 'id',
        public ?string $connection = null,
    ) {}

    public function name(): string
    {
        return $this->table.'.files';
    }
}
