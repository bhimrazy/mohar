<?php

namespace App\Modules\Notifications\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\EmailCategory;
use App\Modules\Notifications\Http\Requests\EmailPreferencesRequest;
use App\Modules\Notifications\Services\PreferenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email preferences per address on the account (spec §10). Owners (and users with the "contacts" area)
 * manage every row; other users only their own.
 */
class EmailPreferenceController extends Controller
{
    public function __construct(private PreferenceManager $preferences, private Settings $settings) {}

    public function edit(Request $request, ClientContext $context): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $client = $context->client();
        $editable = $this->allowed() ? $this->preferences->editableScopes($client, $user, $context->can('contacts')) : [];

        return Inertia::render('client/notifications/preferences', [
            'rows' => array_values(array_filter(
                array_map(fn (array $row): array => [...$row, 'editable' => in_array($row['scope'], $editable, true)], $this->preferences->rows($client)),
                fn (array $row): bool => $context->can('contacts') || $row['editable'],
            )),
            'categories' => array_map(fn (EmailCategory $category): array => ['value' => $category->value, 'label' => $category->label(), 'required' => $category->mustReachSomeone()], EmailCategory::cases()),
            'canManage' => $this->allowed(),
        ]);
    }

    public function update(EmailPreferencesRequest $request, ClientContext $context): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->allowed(), 403);
        $client = $context->client();

        /** @var list<array{scope: string, categories: array<string, bool>}> $updates */
        $updates = $request->validated('preferences');
        $this->preferences->update($client, $updates, $this->preferences->editableScopes($client, $user, $context->can('contacts')));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email preferences saved.')]);

        return back();
    }

    private function allowed(): bool
    {
        return (bool) $this->settings->get('notifications.client_can_manage_preferences', true);
    }
}
