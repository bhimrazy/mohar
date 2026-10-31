<?php

namespace App\Modules\Support\Services;

use App\Contracts\Support\PublicHelpDirectory;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Settings\SupportSettings;

/**
 * Public help content for marketing pages and the sitemap (PublicHelpDirectory). Private articles, unpublished or
 * scheduled announcements, and login-only network status never leave this class for guests.
 */
class SupportPublicHelp implements PublicHelpDirectory
{
    public function __construct(private SupportSettings $settings) {}

    public function recentAnnouncements(int $limit): array
    {
        return array_values(Announcement::query()->live()->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Announcement $announcement): array => [
                'title' => $announcement->title,
                'excerpt' => $announcement->excerpt,
                'url' => route('support.announcements.show', $announcement->slug),
                'published_at' => (string) $announcement->published_at?->toIso8601String(),
            ])->all());
    }

    public function popularArticles(int $limit): array
    {
        return array_values(KbArticle::query()->visibleTo(false)->orderByDesc('views')->orderBy('sort_order')->orderBy('id')->limit($limit)->get()
            ->map(fn (KbArticle $article): array => [
                'title' => $article->title,
                'url' => route('support.kb.article', $article->slug),
            ])->all());
    }

    public function networkStatus(bool $signedIn): ?array
    {
        if (! $signedIn && $this->settings->bool('network_status_requires_login', true)) {
            return null;
        }

        $open = NetworkIncident::query()->where('status', '!=', IncidentStatus::Resolved->value)
            ->orderBy('starts_at')->orderBy('id')->limit(5)->get();
        $resolved = NetworkIncident::query()->where('status', IncidentStatus::Resolved->value)
            ->where('ends_at', '>=', now()->subDays(30))->orderByDesc('ends_at')->orderByDesc('id')->limit(1)->get();

        return array_values($open->concat($resolved)->map(fn (NetworkIncident $incident): array => [
            'title' => $incident->title,
            'status' => $incident->status->value,
            'open' => ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Scheduled], true),
            'starts_at' => $incident->starts_at->toIso8601String(),
            'ends_at' => $incident->ends_at?->toIso8601String(),
        ])->all());
    }

    /**
     * Public knowledge-base articles and live announcements for /sitemap.xml.
     *
     * @return iterable<array{loc: string, lastmod: string|null}>
     */
    public function sitemapEntries(): iterable
    {
        foreach (KbArticle::query()->visibleTo(false)->orderBy('id')->lazy() as $article) {
            yield ['loc' => route('support.kb.article', $article->slug), 'lastmod' => $article->updated_at?->toIso8601String()];
        }

        foreach (Announcement::query()->live()->orderBy('id')->lazy() as $announcement) {
            yield ['loc' => route('support.announcements.show', $announcement->slug), 'lastmod' => $announcement->updated_at?->toIso8601String()];
        }
    }
}
