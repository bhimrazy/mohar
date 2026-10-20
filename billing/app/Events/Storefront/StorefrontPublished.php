<?php

namespace App\Events\Storefront;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff published the website draft; visitors now see it.
 */
final class StorefrontPublished implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  list<string>  $changedSections  content keys that differ from the previously published version
     */
    public function __construct(
        public int $revisionId,
        public ?int $publishedBy,
        public array $changedSections,
    ) {}
}
