<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Support\Models\StaffSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The signed-in staff member's reply signature (appended to staff replies).
 */
class StaffSignatureController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('support.tickets.reply');
        $validated = $request->validate(['signature' => ['nullable', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();
        $signature = trim((string) ($validated['signature'] ?? ''));

        if ($signature === '') {
            StaffSignature::query()->where('user_id', $user->id)->delete();
        } else {
            StaffSignature::query()->updateOrCreate(['user_id' => $user->id], ['signature' => $signature]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signature saved.')]);

        return back();
    }
}
