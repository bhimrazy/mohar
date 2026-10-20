<?php

namespace App\Modules\Storefront\Services;

use App\Events\Storefront\StorefrontPublished;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Storefront\Exceptions\DraftChanged;
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
            $before = $draft->exists ? $draft->content : $this->published();
            $content = SiteContent::normalise($content);

            $draft->fill([
                'content' => $content,
                'schema_version' => SiteContent::SCHEMA,
                'created_by' => $user->id,
            ])->save();

            // Draft authors are on record too, not only the publisher (security review L4).
            $this->activity->log('Website draft saved', $draft, properties: ['sections' => SiteContent::changedSections($before, $content)], event: 'storefront.draft_saved', actor: $user);

            return $draft;
        });
    }

    /**
     * Fingerprint of a draft's content, shown with the publish confirmation and checked when publishing, so a draft
     * changed after the publisher reviewed it is never published unseen (security review M1).
     */
    public function reviewToken(Revision $draft): string
    {
        return hash('sha256', $draft->id.'|'.json_encode($draft->content));
    }

    /**
     * Make the draft live. Returns null when there is no draft. With $reviewed (the token the publisher's
     * confirmation showed), a draft that changed since then is refused.
     *
     * @throws DraftChanged
     */
    public function publish(User $user, ?string $reviewed = null): ?Revision
    {
        $published = DB::transaction(function () use ($user, $reviewed): ?array {
            $draft = Revision::query()->where('status', Revision::DRAFT)->lockForUpdate()->orderByDesc('id')->first();

            if ($draft === null) {
                return null;
            }

            if ($reviewed !== null && ! hash_equals($this->reviewToken($draft), $reviewed)) {
                throw new DraftChanged;
            }

            $before = $this->publishedRevision()->content ?? [];
            $changes = SiteContent::changes($before, $draft->content);
            $keys = SiteContent::changedSections($before, $draft->content);

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

    /**
     * Delete the draft. The delete is conditional on the row still being a draft, so a publish that committed in
     * between is never deleted (security review M2).
     */
    public function discardDraft(User $user): bool
    {
        $draft = $this->draft();

        if ($draft === null) {
            return false;
        }

        $deleted = Revision::query()->whereKey($draft->id)->where('status', Revision::DRAFT)->delete() > 0;

        if ($deleted) {
            $this->activity->log('Website draft discarded', properties: ['revision' => $draft->id], event: 'storefront.draft_discarded', actor: $user);
        }

        return $deleted;
    }
}
