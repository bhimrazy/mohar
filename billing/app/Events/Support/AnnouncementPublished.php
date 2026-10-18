<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An announcement became visible.
 */
final class AnnouncementPublished
{
    use Dispatchable;

    public function __construct(
        public int $announcementId,
    ) {}
}
