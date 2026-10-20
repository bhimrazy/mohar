<?php

namespace App\Contracts\Support;

/**
 * Public help content for marketing pages (home page news, help tiles, header badge). Implemented by Support; Core
 * binds an empty default. URLs are absolute; times are ISO 8601.
 */
interface PublicHelpDirectory
{
    /**
     * Announcements that are live now, newest first.
     *
     * @return list<array{title: string, excerpt: string|null, url: string, published_at: string}>
     */
    public function recentAnnouncements(int $limit): array;

    /**
     * Published knowledge-base articles anyone may read (never private ones), most viewed first.
     *
     * @return list<array{title: string, url: string}>
     */
    public function popularArticles(int $limit): array;

    /**
     * Open and scheduled incidents, then the most recently resolved one (resolved in the last 30 days); null when
     * the visitor may not see network status (it requires login and $signedIn is false).
     *
     * @return list<array{title: string, status: string, open: bool, starts_at: string, ends_at: string|null}>|null
     */
    public function networkStatus(bool $signedIn): ?array;
}
