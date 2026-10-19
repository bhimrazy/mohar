<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Notifications\Enums\EmailCategory;
use App\Modules\Notifications\Models\ClientEmailPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes a client account's email preferences (spec §2, §7, §10): the primary address (shared
 * by owner logins), every other login, and contacts (contacts.email_preferences, owned by Core).
 */
class PreferenceManager
{
    /**
     * @return list<array{scope: string, kind: string, name: string, email: string, categories: array<string, bool>}>
     */
    public function rows(Client $client): array
    {
        $stored = ClientEmailPreference::query()->where('client_id', $client->id)->get()->keyBy('scope_key');
        $defaults = array_fill_keys(EmailCategory::values(), true);

        $rows = [[
            'scope' => ClientEmailPreference::OWNER,
            'kind' => 'owner',
            'name' => trim($client->first_name.' '.$client->last_name),
            'email' => $client->email,
            'categories' => [...$defaults, ...($stored->get(ClientEmailPreference::OWNER)->categories ?? [])],
        ]];

        $users = $client->users()->wherePivot('is_owner', false)->orderBy('users.id')->get();

        foreach ($users as $user) {
            $scope = ClientEmailPreference::scopeFor($user->id);
            $rows[] = ['scope' => $scope, 'kind' => 'user', 'name' => $user->name, 'email' => $user->email, 'categories' => [...$defaults, ...($stored->get($scope)->categories ?? [])]];
        }

        foreach ($client->contacts()->orderBy('id')->get() as $contact) {
            $rows[] = [
                'scope' => 'contact:'.$contact->id,
                'kind' => 'contact',
                'name' => trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email,
                'categories' => array_map('boolval', [...array_fill_keys(EmailCategory::values(), false), ...array_intersect_key($contact->email_preferences, $defaults)]),
            ];
        }

        return $rows;
    }

    /**
     * Applies the changes for the given scopes. Invoice and domain mail must keep at least one recipient.
     *
     * @param  list<array{scope: string, categories: array<string, bool>}>  $updates
     * @param  list<string>  $editableScopes
     *
     * @throws ValidationException
     */
    public function update(Client $client, array $updates, array $editableScopes): void
    {
        $rows = collect($this->rows($client))->keyBy('scope');

        foreach ($updates as $update) {
            if (! $rows->has($update['scope']) || ! in_array($update['scope'], $editableScopes, true)) {
                throw ValidationException::withMessages(['preferences' => __('You cannot change the preferences of :scope.', ['scope' => $update['scope']])]);
            }

            $row = $rows->get($update['scope']);
            $row['categories'] = [...$row['categories'], ...array_map('boolval', array_intersect_key($update['categories'], $row['categories']))];
            $rows->put($update['scope'], $row);
        }

        foreach (EmailCategory::cases() as $category) {
            if ($category->mustReachSomeone() && $rows->doesntContain(fn (array $row): bool => $row['categories'][$category->value])) {
                throw ValidationException::withMessages(['preferences' => __('At least one address must keep receiving :category emails.', ['category' => strtolower($category->label())])]);
            }
        }

        DB::transaction(function () use ($client, $rows, $updates): void {
            foreach (array_unique(array_column($updates, 'scope')) as $scope) {
                $categories = $rows->get($scope)['categories'];

                if (str_starts_with($scope, 'contact:')) {
                    $contact = Contact::query()->where('client_id', $client->id)->findOrFail((int) substr($scope, 8));
                    $contact->update(['email_preferences' => [...$contact->email_preferences, ...$categories]]);

                    continue;
                }

                ClientEmailPreference::query()->updateOrCreate(
                    ['client_id' => $client->id, 'scope_key' => $scope],
                    ['user_id' => $scope === ClientEmailPreference::OWNER ? null : (int) substr($scope, 5), 'categories' => $categories],
                );
            }
        });
    }

    /**
     * Scopes a client-area user may change: everything with the "contacts" area (owners), else only their own row.
     *
     * @return list<string>
     */
    public function editableScopes(Client $client, User $user, bool $canManageOthers): array
    {
        if ($canManageOthers) {
            return array_column($this->rows($client), 'scope');
        }

        return [ClientEmailPreference::scopeFor($user->id)];
    }
}
