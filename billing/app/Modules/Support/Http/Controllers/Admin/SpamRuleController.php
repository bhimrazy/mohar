<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\SpamRuleRequest;
use App\Modules\Support\Models\SpamRule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SpamRuleController extends Controller
{
    public function store(SpamRuleRequest $request): RedirectResponse
    {
        SpamRule::query()->create($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spam rule added.')]);

        return back();
    }

    public function destroy(SpamRule $spamRule): RedirectResponse
    {
        $this->authorize('support.inbound.manage');
        $spamRule->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spam rule removed.')]);

        return back();
    }
}
