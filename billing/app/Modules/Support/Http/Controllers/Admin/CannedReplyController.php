<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\CannedReplyRequest;
use App\Modules\Support\Models\CannedReply;
use App\Modules\Support\Models\CannedReplyCategory;
use App\Modules\Support\Services\MergeTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CannedReplyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('support.canned.manage');

        return Inertia::render('admin/support/canned-replies/index', [
            'replies' => CannedReply::query()->with('category:id,name')
                ->search($request->string('search')->toString())
                ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
                ->map(fn (CannedReply $reply): array => [
                    'id' => $reply->id,
                    'name' => $reply->name,
                    'category' => $reply->category?->name,
                    'excerpt' => str($reply->body)->squish()->limit(120)->toString(),
                    'updated_at' => $reply->updated_at?->toIso8601String(),
                ]),
            'categories' => $this->categories(),
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('support.canned.manage');

        return Inertia::render('admin/support/canned-replies/form', ['categories' => $this->categories(), 'tokens' => MergeTokens::TOKENS]);
    }

    public function store(CannedReplyRequest $request): RedirectResponse
    {
        $reply = CannedReply::query()->create([...$request->validated(), 'sort_order' => $request->integer('sort_order')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Canned reply ":name" created.', ['name' => $reply->name])]);

        return to_route('admin.support.canned-replies.index');
    }

    public function edit(CannedReply $cannedReply): Response
    {
        $this->authorize('support.canned.manage');

        return Inertia::render('admin/support/canned-replies/form', [
            'reply' => $cannedReply->only(['id', 'name', 'category_id', 'body', 'sort_order']),
            'categories' => $this->categories(),
            'tokens' => MergeTokens::TOKENS,
        ]);
    }

    public function update(CannedReplyRequest $request, CannedReply $cannedReply): RedirectResponse
    {
        $cannedReply->update([...$request->validated(), 'sort_order' => $request->integer('sort_order')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Canned reply ":name" updated.', ['name' => $cannedReply->name])]);

        return to_route('admin.support.canned-replies.index');
    }

    public function destroy(CannedReply $cannedReply): RedirectResponse
    {
        $this->authorize('support.canned.manage');
        $cannedReply->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Canned reply ":name" deleted.', ['name' => $cannedReply->name])]);

        return to_route('admin.support.canned-replies.index');
    }

    /**
     * Flat list with child categories labelled "Parent › Child".
     *
     * @return list<array{value: string, label: string}>
     */
    private function categories(): array
    {
        $categories = CannedReplyCategory::query()->orderBy('name')->orderBy('id')->get();

        return array_values($categories->map(fn (CannedReplyCategory $category): array => [
            'value' => (string) $category->id,
            'label' => ($category->parent_id !== null ? ($categories->firstWhere('id', $category->parent_id)?->name.' › ') : '').$category->name,
        ])->sortBy('label')->values()->all());
    }
}
