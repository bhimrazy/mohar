<?php

namespace App\Modules\Storefront\Services;

use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;

/**
 * The website content visitors see: the newest published revision, cached with the other public payloads.
 */
class ContentStore
{
    public function __construct(private StorefrontCache $cache) {}

    /**
     * What visitors see: the newest published version, else the defaults (fresh install).
     *
     * @return array<string, mixed>
     */
    public function published(): array
    {
        return $this->cache->remember('content', fn (): array => SiteContent::normalise($this->publishedRevision()->content ?? []));
    }

    public function publishedRevision(): ?Revision
    {
        return Revision::query()->where('status', Revision::PUBLISHED)->orderByDesc('id')->first();
    }
}
