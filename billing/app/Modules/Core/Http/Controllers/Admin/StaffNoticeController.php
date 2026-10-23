<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\StaffNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffNoticeController extends Controller
{
    /** Dismisses a notice for the signed-in person only. */
    public function dismiss(Request $request, string $notice): RedirectResponse
    {
        $this->authorize('core.settings.update');

        StaffNotice::query()
            ->where('user_id', $request->user()->id)
            ->where('notice', $notice)
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);

        return back();
    }
}
