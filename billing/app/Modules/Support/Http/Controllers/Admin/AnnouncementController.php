<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Events\Support\AnnouncementPublished;
use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\AnnouncementRequest;
use App\Modules\Support\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $this->authorize('support.announcements.manage');

        $announcements = Announcement::query()->orderByDesc('published_at')->orderByDesc('id')->paginate(25);

        return Inertia::render('admin/support/announcements/index', [
            'announcements' => $announcements->through(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'slug' => $announcement->slug,
                'status' => $announcement->isLive() ? 'published' : ($announcement->is_published ? 'scheduled' : 'draft'),
                'published_at' => $announcement->published_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('support.announcements.manage');

        return Inertia::render('admin/support/announcements/form');
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = Announcement::query()->create($request->attributesForModel());

        if ($announcement->isLive()) {
            AnnouncementPublished::dispatch($announcement->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement ":title" saved.', ['title' => $announcement->title])]);

        return to_route('admin.support.announcements.index');
    }

    public function edit(Announcement $announcement): Response
    {
        $this->authorize('support.announcements.manage');

        return Inertia::render('admin/support/announcements/form', [
            'announcement' => [
                ...$announcement->only(['id', 'title', 'slug', 'excerpt', 'body', 'is_published']),
                'published_at' => $announcement->published_at?->format('Y-m-d\TH:i'),
            ],
        ]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $wasLive = $announcement->isLive();
        $announcement->update($request->attributesForModel());

        if (! $wasLive && $announcement->isLive()) {
            AnnouncementPublished::dispatch($announcement->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement ":title" saved.', ['title' => $announcement->title])]);

        return to_route('admin.support.announcements.index');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->authorize('support.announcements.manage');
        $announcement->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement ":title" deleted.', ['title' => $announcement->title])]);

        return to_route('admin.support.announcements.index');
    }
}
