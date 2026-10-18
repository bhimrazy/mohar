<?php

namespace App\Data\Install;

/**
 * What one reference-data installer did: rows it created, rows it brought up to date, and rows already current.
 */
final readonly class ReferenceDataResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
    ) {}
}
