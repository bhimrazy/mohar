<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\ActivityLogger;

class SaveContact
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: ?string, email_preferences: list<string>}  $data
     */
    public function handle(Client $client, ?Contact $contact, array $data): Contact
    {
        $contact ??= new Contact(['client_id' => $client->id]);
        $contact->fill([
            ...$data,
            'email_preferences' => array_merge(
                array_fill_keys(Contact::EMAIL_TYPES, false),
                array_fill_keys(array_values(array_intersect($data['email_preferences'], Contact::EMAIL_TYPES)), true),
            ),
        ]);
        $contact->save();

        $this->activity->log(
            $contact->wasRecentlyCreated ? "Contact {$contact->email} added" : "Contact {$contact->email} updated",
            $contact,
            $client->id,
            event: $contact->wasRecentlyCreated ? 'contact.created' : 'contact.updated',
        );

        return $contact;
    }

    public function delete(Client $client, Contact $contact): void
    {
        $contact->delete();
        $this->activity->log("Contact {$contact->email} deleted", clientId: $client->id, event: 'contact.deleted');
    }
}
