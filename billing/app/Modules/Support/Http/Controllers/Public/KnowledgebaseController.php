<?php

namespace App\Modules\Support\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Services\MarkdownRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public knowledge base (spec §7): hidden categories are not listed, private articles need a login,
 * views count once per session and helpful votes once per session/user.
 */
class KnowledgebaseController extends Controller
{
    private const VIEWED = 'support.kb_viewed';

    private const VOTED = 'support.kb_voted';

    public function index(Request $request): Response
    {
        $signedIn = $request->user() !== null;
        $term = trim($request->string('q')->toString());

        return Inertia::render('public/support/kb/index', [
            'categories' => KbCategory::query()->visible()->whereNull('parent_id')->ordered()->orderBy('id')
                ->with(['children' => fn ($query) => $query->visible()->ordered()->orderBy('id')])
                ->withCount(['articles' => fn (Builder $query) => $query->where('is_published', true)->when(! $signedIn, fn (Builder $query) => $query->where('is_private', false))])
                ->get()
                ->map(fn (KbCategory $category): array => [
                    ...$this->presentCategory($category),
                    'articles_count' => (int) $category->getAttribute('articles_count'),
                    'children' => $category->children->map(fn (KbCategory $child): array => $this->presentCategory($child))->all(),
                ]),
            'popular' => $term === '' ? $this->articles(KbArticle::query()->visibleTo($signedIn)->orderByDesc('views')->orderBy('id')->limit(6)) : [],
            'results' => $term !== '' ? $this->articles(KbArticle::query()->visibleTo($signedIn)->search($term)->orderByDesc('views')->orderBy('id')->limit(25)) : null,
            'query' => $term,
        ]);
    }

    public function category(Request $request, string $slug): Response
    {
        $category = KbCategory::query()->visible()->where('slug', $slug)->with('parent')->firstOrFail();
        $signedIn = $request->user() !== null;

        return Inertia::render('public/support/kb/category', [
            'category' => [...$this->presentCategory($category), 'parent' => $category->parent ? $this->presentCategory($category->parent) : null],
            'children' => KbCategory::query()->visible()->where('parent_id', $category->id)->ordered()->orderBy('id')->get()
                ->map(fn (KbCategory $child): array => $this->presentCategory($child)),
            'articles' => $this->articles($category->articles()->getQuery()->visibleTo($signedIn)->orderBy('sort_order')->orderBy('title')->orderBy('support_kb_articles.id')),
        ]);
    }

    public function article(Request $request, string $slug, MarkdownRenderer $markdown): Response|RedirectResponse
    {
        $article = KbArticle::query()->where('slug', $slug)->where('is_published', true)->with(['categories' => fn ($query) => $query->visible()])->firstOrFail();

        if ($article->is_private && $request->user() === null) {
            return redirect()->guest(route('login'));
        }

        $viewed = (array) $request->session()->get(self::VIEWED, []);

        if (! in_array($article->id, $viewed, true)) {
            KbArticle::query()->whereKey($article->id)->toBase()->increment('views');
            $request->session()->push(self::VIEWED, $article->id);
            $article->views++;
        }

        $categoryIds = $article->categories->pluck('id')->all();

        return Inertia::render('public/support/kb/article', [
            'article' => [
                'title' => $article->title,
                'slug' => $article->slug,
                'body_html' => $markdown->render($article->body),
                'tags' => $article->tags ?? [],
                'views' => $article->views,
                'votes_up' => $article->votes_up,
                'votes_down' => $article->votes_down,
                'is_private' => $article->is_private,
                'updated_at' => $article->updated_at?->toIso8601String(),
                'has_voted' => $this->hasVoted($request, $article),
            ],
            'categories' => $article->categories->map(fn (KbCategory $category): array => $this->presentCategory($category))->values(),
            'related' => $categoryIds === [] ? [] : $this->articles(KbArticle::query()->visibleTo($request->user() !== null)
                ->whereKeyNot($article->id)
                ->whereHas('categories', fn (Builder $query) => $query->whereIn('support_kb_categories.id', $categoryIds))
                ->orderByDesc('views')->orderBy('id')->limit(5)),
        ]);
    }

    public function vote(Request $request, string $slug): RedirectResponse
    {
        $validated = $request->validate(['helpful' => ['required', Rule::in(['yes', 'no'])]]);
        $article = KbArticle::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();
        abort_if($article->is_private && $request->user() === null, 404);

        if (! $this->hasVoted($request, $article)) {
            KbArticle::query()->whereKey($article->id)->toBase()->increment($validated['helpful'] === 'yes' ? 'votes_up' : 'votes_down');
            $request->session()->push(self::VOTED, $article->id);

            if ($request->user() !== null) {
                Cache::forever($this->voteKey($article, (int) $request->user()->id), true);
            }
        }

        return back();
    }

    private function hasVoted(Request $request, KbArticle $article): bool
    {
        return in_array($article->id, (array) $request->session()->get(self::VOTED, []), true)
            || ($request->user() !== null && Cache::has($this->voteKey($article, (int) $request->user()->id)));
    }

    private function voteKey(KbArticle $article, int $userId): string
    {
        return "support.kb_vote.{$article->id}.{$userId}";
    }

    /**
     * @return array{name: string, slug: string, description: string|null}
     */
    private function presentCategory(KbCategory $category): array
    {
        return ['name' => $category->name, 'slug' => $category->slug, 'description' => $category->description];
    }

    /**
     * @param  Builder<KbArticle>  $query
     * @return list<array{title: string, slug: string, excerpt: string, views: int, is_private: bool}>
     */
    private function articles(Builder $query): array
    {
        return array_values($query->get()->map(fn (KbArticle $article): array => [
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => Str::of($article->body)->replaceMatches('/[#*_`>\[\]()]/', '')->squish()->limit(160)->toString(),
            'views' => $article->views,
            'is_private' => $article->is_private,
        ])->all());
    }
}
