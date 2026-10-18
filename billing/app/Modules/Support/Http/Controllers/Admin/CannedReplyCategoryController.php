<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\CannedReplyCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CannedReplyCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('support.canned.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('support_canned_reply_categories', 'id')],
        ]);

        CannedReplyCategory::query()->create($validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category ":name" created.', ['name' => $validated['name']])]);

        return back();
    }

    public function destroy(CannedReplyCategory $category): RedirectResponse
    {
        $this->authorize('support.canned.manage');
        $category->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category ":name" deleted.', ['name' => $category->name])]);

        return back();
    }
}
