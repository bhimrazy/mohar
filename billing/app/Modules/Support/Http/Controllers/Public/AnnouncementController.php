<?php

namespace App\Modules\Support\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Services\MarkdownRenderer;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public announcements; scheduled ones stay hidden until published_at (spec §7, AC21).
 */
class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $announcements = Announcement::query()->live()->orderByDesc('published_at')->orderByDesc('id')->paginate(10);

        return Inertia::render('public/support/announcements/index', [
            'announcements' => $announcements->through(fn (Announcement $announcement): array => [
                'title' => $announcement->title,
                'slug' => $announcement->slug,
                'excerpt' => $announcement->excerpt,
                'published_at' => $announcement->published_at?->toIso8601String(),
            ]),
            'feedUrl' => route('support.announcements.feed'),
        ]);
    }

    public function show(string $slug, MarkdownRenderer $markdown): Response
    {
        $announcement = Announcement::query()->live()->where('slug', $slug)->firstOrFail();

        return Inertia::render('public/support/announcements/show', [
            'announcement' => [
                'title' => $announcement->title,
                'excerpt' => $announcement->excerpt,
                'body_html' => $markdown->render($announcement->body),
                'published_at' => $announcement->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function feed(MarkdownRenderer $markdown): HttpResponse
    {
        $items = Announcement::query()->live()->orderByDesc('published_at')->orderByDesc('id')->limit(20)->get();
        $esc = fn (?string $value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0"><channel>'
            .'<title>'.$esc(config('app.name').' announcements').'</title>'
            .'<link>'.$esc(route('support.announcements.index')).'</link>'
            .'<description>'.$esc('Latest announcements').'</description>';

        foreach ($items as $item) {
            $url = route('support.announcements.show', $item->slug);
            $xml .= '<item><title>'.$esc($item->title).'</title><link>'.$esc($url).'</link><guid>'.$esc($url).'</guid>'
                .'<pubDate>'.$esc($item->published_at?->toRfc2822String()).'</pubDate>'
                .'<description>'.$esc($markdown->render($item->body)).'</description></item>';
        }

        $xml .= '</channel></rss>';

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
