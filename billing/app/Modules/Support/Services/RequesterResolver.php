<?php

namespace App\Modules\Support\Services;

use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Maps an email address to a client account (owner email, linked login or contact). Only an
 * unambiguous match attaches; it never grants the guest a login (spec §4.1, §13).
 *
 * An address counts only when it is proved or vouched for: an account email set by staff or an import
 * (EmailSource::isVouched()), a login that verified its email, or a contact (staff or import only). An
 * account email the client typed in counts only through a login of that client that verified it.
 * Anything else is treated like an unknown sender.
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

        $owners = $this->vouchedOwners($email)->pluck('id')->all();
        $logins = $this->verifiedLogins($email)->pluck('client_user.client_id')->all();
        $contacts = Contact::query()->whereRaw('lower(email) = ?', [$email])->get(['id', 'client_id']);

        $clientIds = array_values(array_unique(array_map('intval', [...$owners, ...$logins, ...$contacts->pluck('client_id')->all()])));

        if (count($clientIds) !== 1) {
            return null;
        }

        $contact = $contacts->firstWhere('client_id', $clientIds[0]);

        return ['client_id' => $clientIds[0], 'contact_id' => $owners === [] && $logins === [] ? $contact?->id : null];
    }

    /**
     * Name of the login or contact using this address, if any. Same trust rule as match(), so an
     * address a client merely typed in does not label a stranger's mail with that client's name.
     */
    public function nameFor(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->whereNotNull('email_verified_at')->value('name');

        if (is_string($user) && $user !== '') {
            return $user;
        }

        $contact = Contact::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        if ($contact !== null) {
            return trim($contact->first_name.' '.$contact->last_name);
        }

        $client = $this->vouchedOwners($email)->orderBy('id')->first();

        return $client !== null ? trim($client->first_name.' '.$client->last_name) : null;
    }

    /**
     * Whether the address may reply to the ticket by email (spec §6.6): requester, CC, or the
     * vouched account email, a verified login or a contact of the ticket's client.
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

        return $this->vouchedOwners($email)->whereKey($ticket->client_id)->exists()
            || $this->verifiedLogins($email)->where('client_user.client_id', $ticket->client_id)->exists()
            || Contact::query()->where('client_id', $ticket->client_id)->whereRaw('lower(email) = ?', [$email])->exists();
    }

    /**
     * @return Builder<Client>
     */
    private function vouchedOwners(string $email): Builder
    {
        $vouched = array_map(fn (EmailSource $source): string => $source->value, array_filter(EmailSource::cases(), fn (EmailSource $source): bool => $source->isVouched()));

        return Client::query()->whereRaw('lower(email) = ?', [$email])->whereIn('email_source', $vouched);
    }

    private function verifiedLogins(string $email): QueryBuilder
    {
        return DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')
            ->whereRaw('lower(users.email) = ?', [$email])->whereNotNull('users.email_verified_at');
    }
}
