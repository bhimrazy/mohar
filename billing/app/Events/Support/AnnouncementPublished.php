<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An announcement became visible.
 */
final class AnnouncementPublished
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $announcementId,
    ) {}
}
