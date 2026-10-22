<?php

namespace App\Support\Security;

/**
 * A database column whose values are Laravel encrypter payloads (encrypted with APP_KEY), for `security:reencrypt`.
 * Columns behind an Eloquent encrypted cast are found by the command itself; register the others in
 * EncryptedStorage (values written with Crypt directly, or by a package).
 *
 *  - $prefix: stored values start with it and the payload follows (e.g. "enc:v1:"); values without it are not
 *    encrypted and are left alone.
 *  - $json: the column holds the value as a JSON string (a `json` cast), so it is decoded before and encoded after.
 *  - $intKey: the key column is an integer (keyset paging compares it as one).
 */
final readonly class EncryptedColumn
{
    public function __construct(
        public string $table,
        public string $column,
        public string $keyColumn = 'id',
        public bool $intKey = true,
        public string $prefix = '',
        public bool $json = false,
        public ?string $connection = null,
    ) {}

    public function name(): string
    {
        return $this->table.'.'.$this->column;
    }
}
