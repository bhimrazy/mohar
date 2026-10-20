<?php

namespace App\Modules\Core\Support;

use App\Contracts\Support\PublicHelpDirectory;

/**
 * Default when Support is not installed: no help content.
 */
class NullPublicHelpDirectory implements PublicHelpDirectory
{
    public function recentAnnouncements(int $limit): array
    {
        return [];
    }

    public function popularArticles(int $limit): array
    {
        return [];
    }

    public function networkStatus(bool $signedIn): ?array
    {
        return null;
    }
}
