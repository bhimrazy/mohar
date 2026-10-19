<?php

namespace App\Modules\Storefront\Services;

use App\Events\Storefront\StorefrontPublished;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

/**
 * Draft, publish and discard for the website content (storefront spec §6.1). Saving a draft never changes what
 * visitors see; publishing archives the previous version, flushes the public cache and records an audit entry.
 */
class ContentStore
{
    public function __construct(
        private ActivityLogger $activity,
        private StorefrontCache $cache,
    ) {}

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

    public function draft(): ?Revision
    {
        return Revision::query()->where('status', Revision::DRAFT)->orderByDesc('id')->first();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function saveDraft(array $content, User $user): Revision
    {
        return DB::transaction(function () use ($content, $user): Revision {
            $draft = Revision::query()->where('status', Revision::DRAFT)->lockForUpdate()->orderByDesc('id')->first()
                ?? new Revision(['status' => Revision::DRAFT]);

            $draft->fill([
                'content' => SiteContent::normalise($content),
                'schema_version' => SiteContent::SCHEMA,
                'created_by' => $user->id,
            ])->save();

            return $draft;
        });
    }

    /**
     * Make the draft live. Returns null when there is no draft.
     */
    public function publish(User $user): ?Revision
    {
        $published = DB::transaction(function () use ($user): ?array {
            $draft = Revision::query()->where('status', Revision::DRAFT)->lockForUpdate()->orderByDesc('id')->first();

            if ($draft === null) {
                return null;
            }

            $before = $this->publishedRevision()->content ?? [];
            $changes = SiteContent::changes($before, $draft->content);
            $keys = array_values(array_filter(
                array_keys(SiteContent::defaults()),
                fn (string $key): bool => (SiteContent::normalise($before)[$key] ?? null) !== (SiteContent::normalise($draft->content)[$key] ?? null),
            ));

            Revision::query()->where('status', Revision::PUBLISHED)->update(['status' => Revision::ARCHIVED]);
            $draft->fill(['status' => Revision::PUBLISHED, 'published_by' => $user->id, 'published_at' => now()])->save();

            $this->activity->log('Website published', $draft, properties: ['changes' => $changes], event: 'storefront.published', actor: $user);

            return [$draft, $keys];
        });

        if ($published === null) {
            return null;
        }

        [$revision, $keys] = $published;
        $this->cache->flush();
        StorefrontPublished::dispatch($revision->id, $user->id, $keys);

        return $revision;
    }

    public function discardDraft(User $user): bool
    {
        $draft = $this->draft();

        if ($draft === null) {
            return false;
        }

        $draft->delete();
        $this->activity->log('Website draft discarded', properties: ['revision' => $draft->id], event: 'storefront.draft_discarded', actor: $user);

        return true;
    }
}
