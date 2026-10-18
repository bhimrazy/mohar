<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\KbCategoryRequest;
use App\Modules\Support\Models\KbCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class KbCategoryController extends Controller
{
    public function store(KbCategoryRequest $request): RedirectResponse
    {
        $category = KbCategory::query()->create($request->attributesForModel());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category ":name" created.', ['name' => $category->name])]);

        return back();
    }

    public function update(KbCategoryRequest $request, KbCategory $category): RedirectResponse
    {
        if ($request->filled('parent_id') && $category->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => __('Categories can only be nested one level deep.')]);
        }

        $category->update($request->attributesForModel());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category ":name" updated.', ['name' => $category->name])]);

        return back();
    }

    public function destroy(KbCategory $category): RedirectResponse
    {
        $this->authorize('support.kb.manage');
        $category->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category ":name" deleted. Its articles were kept.', ['name' => $category->name])]);

        return back();
    }
}
