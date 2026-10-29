<?php

namespace App\Support\Seo;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Public URLs for /sitemap.xml (ADR 0012 registry shape). Modules register a source in bootModule():
 *
 *   app(SitemapRegistry::class)->register('support_kb', fn (): iterable => [['loc' => $url, 'lastmod' => $iso]]);
 *
 * A source lists only indexable pages: published, public, not hidden and not noindex. Sources run when the sitemap is
 * built; one that throws is reported and left out, so it never breaks the others.
 *
 * @api
 */
class SitemapRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, Closure(): iterable<array{loc: string, lastmod: string|null}>> */
    private array $sources = [];

    /**
     * @param  Closure(): iterable<array{loc: string, lastmod: string|null}>  $source
     */
    public function register(string $key, Closure $source, bool $replace = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid sitemap source key [{$key}].");
        }

        if (isset($this->sources[$key])) {
            if (! $replace) {
                throw new LogicException("A sitemap source is already registered as [{$key}].");
            }

            Log::info('Sitemap source replaced.', ['key' => $key]);
        }

        $this->sources[$key] = $source;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * Keys in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
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
            try {
                foreach ($source() as $entry) {
                    $entries[$entry['loc']] ??= ['loc' => $entry['loc'], 'lastmod' => $entry['lastmod'] ?? null];
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return array_values($entries);
    }
}
