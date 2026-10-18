<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Themes\Http\Requests\ThemePreferenceRequest;
use Illuminate\Http\RedirectResponse;

/**
 * A staff member's own admin theme and density (when Settings → Appearance allows it).
 */
class ThemePreferenceController extends Controller
{
    public function __invoke(ThemePreferenceRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $user->forceFill([
            'theme_preference' => $request->validated('theme'),
            'density_preference' => $request->validated('density'),
        ])->save();

        return back();
    }
}
