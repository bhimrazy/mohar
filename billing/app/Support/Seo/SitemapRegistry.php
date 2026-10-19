<?php

namespace App\Support\Seo;

use Closure;

/**
 * Public URLs for /sitemap.xml (ADR 0012 registry). Modules add a source in bootModule():
 *
 *   app(SitemapRegistry::class)->add('support.kb', fn (): iterable => [['loc' => $url, 'lastmod' => $iso]]);
 *
 * A source lists only indexable pages: published, public, not hidden and not noindex.
 */
class SitemapRegistry
{
    /** @var array<string, Closure(): iterable<array{loc: string, lastmod: string|null}>> */
    private array $sources = [];

    /**
     * @param  Closure(): iterable<array{loc: string, lastmod: string|null}>  $source
     */
    public function add(string $key, Closure $source): void
    {
        $this->sources[$key] = $source;
    }

    /**
     * Every entry, de-duplicated by URL, in registration order.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->sources as $source) {
            foreach ($source() as $entry) {
                $entries[$entry['loc']] ??= ['loc' => $entry['loc'], 'lastmod' => $entry['lastmod'] ?? null];
            }
        }

        return array_values($entries);
    }
}
