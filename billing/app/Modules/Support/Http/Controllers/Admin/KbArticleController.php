<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Actions\SaveKbArticle;
use App\Modules\Support\Http\Requests\KbArticleRequest;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Services\MarkdownRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Knowledge base editor: articles list (with categories managed on the same page) and article form.
 */
class KbArticleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('support.kb.manage');

        $articles = KbArticle::query()->with('categories:id,name')
            ->search($request->string('search')->toString())
            ->when($request->filled('category'), fn (Builder $query) => $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($request->integer('category'))))
            ->when($request->query('state') === 'draft', fn (Builder $query) => $query->where('is_published', false))
            ->when($request->query('state') === 'published', fn (Builder $query) => $query->where('is_published', true))
            ->orderBy('sort_order')->orderBy('title')->orderBy('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('admin/support/kb/index', [
            'articles' => $articles->through(fn (KbArticle $article): array => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'is_published' => $article->is_published,
                'is_private' => $article->is_private,
                'views' => $article->views,
                'votes_up' => $article->votes_up,
                'votes_down' => $article->votes_down,
                'categories' => $article->categories->pluck('name')->all(),
                'updated_at' => $article->updated_at?->toIso8601String(),
            ]),
            'categories' => KbCategory::query()->ordered()->orderBy('id')->withCount('articles')->get()->map(fn (KbCategory $category): array => [
                ...$category->only(['id', 'name', 'slug', 'description', 'parent_id', 'is_hidden', 'sort_order']),
                'articles_count' => (int) $category->getAttribute('articles_count'),
            ]),
            'filters' => $request->only(['search', 'category', 'state']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('support.kb.manage');

        return Inertia::render('admin/support/kb/form', ['categories' => $this->categoryOptions()]);
    }

    public function store(KbArticleRequest $request, SaveKbArticle $save): RedirectResponse
    {
        $article = $save->handle(null, $request->attributesForModel(), $request->categoryIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Article ":title" created.', ['title' => $article->title])]);

        return to_route('admin.support.kb.articles.edit', $article);
    }

    public function edit(KbArticle $article, MarkdownRenderer $markdown): Response
    {
        $this->authorize('support.kb.manage');

        return Inertia::render('admin/support/kb/form', [
            'article' => [
                ...$article->only(['id', 'title', 'slug', 'body', 'is_published', 'is_private', 'sort_order', 'views', 'votes_up', 'votes_down']),
                'tags' => $article->tags ?? [],
                'categories' => $article->categories()->pluck('support_kb_categories.id')->map(fn (int $id): string => (string) $id)->all(),
                'body_html' => $markdown->render($article->body),
                'public_url' => route('support.kb.article', $article->slug),
            ],
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(KbArticleRequest $request, KbArticle $article, SaveKbArticle $save): RedirectResponse
    {
        $save->handle($article, $request->attributesForModel(), $request->categoryIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Article ":title" updated.', ['title' => $article->title])]);

        return to_route('admin.support.kb.articles.edit', $article);
    }

    public function destroy(KbArticle $article): RedirectResponse
    {
        $this->authorize('support.kb.manage');
        $article->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Article ":title" deleted.', ['title' => $article->title])]);

        return to_route('admin.support.kb.articles.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        $categories = KbCategory::query()->ordered()->orderBy('id')->get();

        return array_values($categories->map(fn (KbCategory $category): array => [
            'value' => (string) $category->id,
            'label' => ($category->parent_id !== null ? ($categories->firstWhere('id', $category->parent_id)?->name.' › ') : '').$category->name,
        ])->all());
    }
}
