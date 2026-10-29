<?php

namespace Tests\Fixtures\Migration\Import;

use App\Modules\Core\Notifications\LoginEmailNotice;

/**
 * Probe for the record-importer Arch rule: an importer that builds its module's notification.
 */
final class NoticeImporter
{
    public function upsert(): string
    {
        return LoginEmailNotice::class;
    }
}
