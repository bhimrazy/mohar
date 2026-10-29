<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Platform\Actions\Settings\UpdateSettings;
use App\Modules\Platform\Http\Requests\SettingsRequest;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Platform\Support\AccountMail;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, SettingsCatalog $catalog, MailServerSettings $mail, ?string $section = null): Response|RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $sections = $catalog->visibleSections($user);
        abort_if($sections === [], 403);

        if ($section === null) {
            return to_route('admin.platform.settings.edit', ['section' => array_key_first($sections)]);
        }

        abort_unless(array_key_exists($section, $sections), $catalog->section($section) === null ? 404 : 403);

        if ($catalog->canEdit($user, $section) && $catalog->requiresConfirmation($section) && ! RequireRecentPasswordConfirmation::recentlyConfirmed($request)) {
            return redirect()->guest(route('password.confirm'));
        }

        return Inertia::render('admin/platform/settings/edit', [
            'sections' => array_map(
                fn (string $key, array $definition): array => ['key' => $key, 'title' => $definition['title']],
                array_keys($sections),
                array_values($sections),
            ),
            'section' => [
                'key' => $section,
                'title' => $sections[$section]['title'],
                'editable' => $catalog->canEdit($user, $section),
                'fields' => $catalog->fields($section),
            ],
            'mailTest' => $section === MailServerSettings::SECTION && $catalog->canEdit($user, $section) ? [
                'default_to' => $user->email,
                'saved_here' => $mail->savedHere(),
                'max_attempts' => MailTestController::MAX_ATTEMPTS,
            ] : null,
        ]);
    }

    public function update(SettingsRequest $request, string $section, UpdateSettings $update, AccountMail $mail): RedirectResponse
    {
        $changed = $update->handle($section, $request->validated());

        Inertia::flash('toast', $changed === []
            ? ['type' => 'info', 'message' => __('No changes to save.')]
            : $mail->toast(['type' => 'success', 'message' => trans_choice('{1} 1 setting saved.|[2,*] :count settings saved.', count($changed))]));

        return to_route('admin.platform.settings.edit', ['section' => $section]);
    }
}
