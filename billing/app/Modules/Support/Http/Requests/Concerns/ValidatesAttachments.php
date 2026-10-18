<?php

namespace App\Modules\Support\Http\Requests\Concerns;

use App\Modules\Support\Rules\SupportAttachment;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\IncomingFile;
use Illuminate\Support\Arr;

/**
 * Shared "attachments[]" validation for ticket forms.
 */
trait ValidatesAttachments
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function attachmentRules(bool $staff): array
    {
        $store = app(AttachmentStore::class);

        return [
            'attachments' => ['nullable', 'array', 'max:'.$store->maxFiles()],
            'attachments.*' => ['file', new SupportAttachment($staff)],
        ];
    }

    /**
     * @return list<IncomingFile>
     */
    public function incomingFiles(): array
    {
        $incoming = [];

        foreach (Arr::wrap($this->file('attachments')) as $file) {
            $incoming[] = IncomingFile::fromUpload($file);
        }

        return $incoming;
    }

    /**
     * @return array<string, string>
     */
    protected function attachmentMessages(): array
    {
        return ['attachments.max' => __('Attach at most :max files.')];
    }
}
