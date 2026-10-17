<?php

namespace App\Modules\Support\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Maps an email address to a client account (owner email, linked login or contact). Only an
 * unambiguous match attaches; it never grants the guest a login (spec §4.1, §13).
 */
class RequesterResolver
{
    /**
     * @return array{client_id: int, contact_id: int|null}|null
     */
    public function match(string $email): ?array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        $owners = Client::query()->whereRaw('lower(email) = ?', [$email])->pluck('id')->all();
        $logins = DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')
            ->whereRaw('lower(users.email) = ?', [$email])->pluck('client_user.client_id')->all();
        $contacts = Contact::query()->whereRaw('lower(email) = ?', [$email])->get(['id', 'client_id']);

        $clientIds = array_values(array_unique(array_map('intval', [...$owners, ...$logins, ...$contacts->pluck('client_id')->all()])));

        if (count($clientIds) !== 1) {
            return null;
        }

        $contact = $contacts->firstWhere('client_id', $clientIds[0]);

        return ['client_id' => $clientIds[0], 'contact_id' => $owners === [] && $logins === [] ? $contact?->id : null];
    }

    /**
     * Name of the login or contact using this address, if any.
     */
    public function nameFor(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->value('name');

        if (is_string($user) && $user !== '') {
            return $user;
        }

        $contact = Contact::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        if ($contact !== null) {
            return trim($contact->first_name.' '.$contact->last_name);
        }

        $client = Client::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        return $client !== null ? trim($client->first_name.' '.$client->last_name) : null;
    }

    /**
     * Whether the address may reply to the ticket by email (spec §6.6): requester, CC, or any
     * login or contact of the ticket's client.
     */
    public function isAuthorizedSender(Ticket $ticket, string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower($ticket->requester_email) || in_array($email, $ticket->cc ?? [], true)) {
            return true;
        }

        if ($ticket->client_id === null) {
            return false;
        }

        return Client::query()->whereKey($ticket->client_id)->whereRaw('lower(email) = ?', [$email])->exists()
            || DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')
                ->where('client_user.client_id', $ticket->client_id)->whereRaw('lower(users.email) = ?', [$email])->exists()
            || Contact::query()->where('client_id', $ticket->client_id)->whereRaw('lower(email) = ?', [$email])->exists();
    }
}
