<?php

namespace App\Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Storefront\Support\StorefrontCache;
use App\Support\Seo\SitemapRegistry;
use Illuminate\Http\Response;

/**
 * /sitemap.xml (spec §7.4): indexable public pages from every module's SitemapRegistry source. Served outside the
 * session middleware and cached with the other public payloads.
 */
class SitemapController extends Controller
{
    public function __invoke(SitemapRegistry $registry, StorefrontCache $cache): Response
    {
        $xml = $cache->remember('sitemap', function () use ($registry): string {
            $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

            foreach ($registry->entries() as $entry) {
                $lines[] = '  <url><loc>'.e($entry['loc']).'</loc>'.($entry['lastmod'] !== null ? '<lastmod>'.e($entry['lastmod']).'</lastmod>' : '').'</url>';
            }

            $lines[] = '</urlset>';

            return implode("\n", $lines)."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
    }
}
