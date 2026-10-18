<?php

namespace App\Modules\Support\Rules;

use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\IncomingFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Applies the attachment policy (spec §4.3) to one uploaded file.
 */
class SupportAttachment implements ValidationRule
{
    public function __construct(private bool $staff = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('The file could not be uploaded.'));

            return;
        }

        $reason = app(AttachmentStore::class)->rejectionReason(IncomingFile::fromUpload($value), $this->staff);

        if ($reason !== null) {
            $fail($value->getClientOriginalName().': '.$reason);
        }
    }
}
