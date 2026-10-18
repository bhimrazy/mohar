<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\EmailCategory;
use App\Modules\Notifications\Enums\StaffCategory;
use App\Modules\Notifications\Models\ClientEmailPreference;
use App\Modules\Notifications\Models\RoleEmailSubscription;

/**
 * Who receives an email (spec §7): client-side by preference category, staff-side by role subscription.
 */
class RecipientResolver
{
    /**
     * The account's primary address, every other login and every contact that has the category on,
     * de-duplicated case-insensitively. Invoice and domain mail falls back to the primary address when
     * nobody opted in. Closed accounts get nothing unless $always (auth mail).
     *
     * @return list<Recipient>
     */
    public function forClient(int $clientId, EmailCategory $category, bool $always = false): array
    {
        $client = Client::query()->with(['users', 'contacts'])->find($clientId);

        if ($client === null || (! $always && $client->status === 'closed')) {
            return [];
        }

        $preferences = ClientEmailPreference::query()->where('client_id', $client->id)->get()->keyBy('scope_key');
        $owner = $this->owner($client);
        $recipients = [];

        if ($always || ($preferences->get(ClientEmailPreference::OWNER)?->wants($category->value) ?? true)) {
            $recipients[] = $owner;
        }

        foreach ($client->users->sortBy('id') as $user) {
            // Owner logins share the account owner's preferences.
            $scope = (bool) $user->getRelationValue('pivot')?->getAttribute('is_owner') ? ClientEmailPreference::OWNER : ClientEmailPreference::scopeFor($user->id);

            if ($always || ($preferences->get($scope)?->wants($category->value) ?? true)) {
                $recipients[] = new Recipient($user->email, $user->name, $client->language, $client->id, userId: $user->id);
            }
        }

        foreach ($client->contacts->sortBy('id') as $contact) {
            if ($always || (bool) ($contact->email_preferences[$category->value] ?? false)) {
                $recipients[] = $this->contact($client, $contact);
            }
        }

        $recipients = $this->unique($recipients);

        if ($recipients === [] && $category->mustReachSomeone()) {
            return [$owner];
        }

        return $recipients;
    }

    public function ownerWants(Client $client, EmailCategory $category): bool
    {
        return $client->status !== 'closed' && (ClientEmailPreference::query()
            ->where('client_id', $client->id)->where('scope_key', ClientEmailPreference::OWNER)
            ->first()?->wants($category->value) ?? true);
    }

    /**
     * Active full administrators (role grants "*").
     *
     * @return list<Recipient>
     */
    public function administrators(): array
    {
        return $this->unique(array_values(User::query()->with('role')
            ->where('is_staff', true)->where('is_active', true)->whereNotNull('role_id')->orderBy('id')->get()
            ->filter(fn (User $user): bool => in_array('*', $user->role->permissions ?? [], true))
            ->map(fn (User $user): Recipient => new Recipient($user->email, $user->name, staffId: $user->id))
            ->all()));
    }

    public function owner(Client $client): Recipient
    {
        return new Recipient($client->email, trim($client->first_name.' '.$client->last_name), $client->language, $client->id);
    }

    public function contact(Client $client, Contact $contact): Recipient
    {
        return new Recipient($contact->email, trim($contact->first_name.' '.$contact->last_name), $client->language, $client->id, contactId: $contact->id);
    }

    /**
     * Arbitrary addresses (ticket requester, CCs, guest senders) — no preference gate.
     *
     * @param  list<string>  $emails
     * @return list<Recipient>
     */
    public function addresses(array $emails, ?Client $client = null, ?string $name = null): array
    {
        return $this->unique(array_values(array_map(
            fn (string $email): Recipient => new Recipient(mb_strtolower(trim($email)), $name ?? '', $client?->language, $client?->id),
            array_filter($emails, fn (string $email): bool => filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false),
        )));
    }

    public function user(int $userId, ?Client $client = null): ?Recipient
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        return $user->is_staff
            ? new Recipient($user->email, $user->name, staffId: $user->id)
            : new Recipient($user->email, $user->name, $client?->language, $client?->id, userId: $user->id);
    }

    /**
     * Active staff whose role subscribes to the category (roles without a subscription row: only
     * full administrators, who receive everything), optionally limited to holders of a permission.
     *
     * @param  list<int>  $onlyUserIds
     * @return list<Recipient>
     */
    public function staff(StaffCategory $category, ?string $permission = null, array $onlyUserIds = []): array
    {
        $subscriptions = RoleEmailSubscription::query()->get()->keyBy('role_id');

        $staff = User::query()->with('role')
            ->where('is_staff', true)->where('is_active', true)->whereNotNull('role_id')
            ->when($onlyUserIds !== [], fn ($query) => $query->whereIn('id', $onlyUserIds))
            ->orderBy('id')->get()
            ->filter(function (User $user) use ($subscriptions, $category, $permission, $onlyUserIds): bool {
                if ($permission !== null && ! $user->hasPermission($permission)) {
                    return false;
                }

                if ($onlyUserIds !== []) {
                    return true;
                }

                $subscription = $subscriptions->get($user->role_id);

                return $subscription !== null
                    ? in_array($category->value, $subscription->categories, true)
                    : in_array('*', $user->role->permissions ?? [], true);
            });

        return $this->unique(array_values($staff->map(fn (User $user): Recipient => new Recipient($user->email, $user->name, staffId: $user->id))->all()));
    }

    /**
     * @param  list<Recipient>  $recipients
     * @return list<Recipient>
     */
    public function unique(array $recipients): array
    {
        $unique = [];

        foreach ($recipients as $recipient) {
            $unique[$recipient->key()] ??= $recipient;
        }

        return array_values($unique);
    }
}
