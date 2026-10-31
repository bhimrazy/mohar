<?php

namespace App\Modules\Support\Boot;

use App\Modules\Support\Services\SupportPublicHelp;
use App\Support\Seo\SitemapRegistry;
use Illuminate\Contracts\Container\Container;

final class Sitemap
{
    public function __invoke(Container $app): void
    {
        $app->make(SitemapRegistry::class)->register('support', fn (): iterable => [
            ['loc' => route('support.kb.index'), 'lastmod' => null],
            ['loc' => route('support.announcements.index'), 'lastmod' => null],
            ...$app->make(SupportPublicHelp::class)->sitemapEntries(),
        ]);
    }
}
