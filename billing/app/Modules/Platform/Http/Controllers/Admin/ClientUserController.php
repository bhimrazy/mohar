<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Http\Requests\ClientUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientUserController extends Controller
{
    public function store(ClientUserRequest $request, Client $client, ManageClientLogins $logins): RedirectResponse
    {
        $user = $logins->invite($client, $request->string('email')->toString(), $request->string('name')->toString(), $this->permissions($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email can now use this account.', ['email' => $user->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function update(ClientUserRequest $request, Client $client, User $user, ManageClientLogins $logins): RedirectResponse
    {
        $this->ensureAttached($client, $user);

        $logins->updatePermissions($client, $user, $this->permissions($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions for :email saved.', ['email' => $user->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function transfer(Client $client, User $user, ManageClientLogins $logins): RedirectResponse
    {
        $this->authorize('core.users.manage');
        $this->ensureAttached($client, $user);

        $logins->transferOwnership($client, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email is now the account owner.', ['email' => $user->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function destroy(Client $client, User $user, ManageClientLogins $logins): RedirectResponse
    {
        $this->authorize('core.users.manage');
        $this->ensureAttached($client, $user);

        $logins->remove($client, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email no longer has access to this account.', ['email' => $user->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function resetTwoFactor(Request $request, Client $client, User $user, ManageClientLogins $logins): RedirectResponse
    {
        $this->authorize('core.users.reset_2fa');
        $this->ensureAttached($client, $user);
        $actor = $request->user();
        abort_if($actor === null, 401);

        $logins->resetTwoFactor($client, $user, $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication reset for :email.', ['email' => $user->email])]);

        return to_route('admin.clients.show', $client);
    }

    private function ensureAttached(Client $client, User $user): void
    {
        abort_unless($client->users()->whereKey($user->id)->exists(), 404);
    }

    /**
     * @return list<string>
     */
    private function permissions(ClientUserRequest $request): array
    {
        return array_values(array_unique(array_map('strval', (array) $request->validated('permissions'))));
    }
}
