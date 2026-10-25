<?php

namespace App\Data\Billing;

/**
 * A rendered billing document stored on a filesystem disk, ready to attach to an email
 * (App\Contracts\Billing\BillingDocuments).
 */
final readonly class DocumentFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $filename,
        public string $mime = 'application/pdf',
    ) {}
}
