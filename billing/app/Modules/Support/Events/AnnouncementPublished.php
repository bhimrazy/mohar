<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An announcement became visible.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class AnnouncementPublished
{
    use Dispatchable;

    public function __construct(
        public int $announcementId,
    ) {}
}
