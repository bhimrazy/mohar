<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Platform\Actions\Clients\SaveContact;
use App\Modules\Platform\Http\Requests\ContactRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClientContactController extends Controller
{
    public function store(ContactRequest $request, Client $client, SaveContact $save): RedirectResponse
    {
        $contact = $save->handle($client, null, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact :email added.', ['email' => $contact->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function update(ContactRequest $request, Client $client, Contact $contact, SaveContact $save): RedirectResponse
    {
        $save->handle($client, $contact, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact :email saved.', ['email' => $contact->email])]);

        return to_route('admin.clients.show', $client);
    }

    public function destroy(Client $client, Contact $contact, SaveContact $save): RedirectResponse
    {
        $this->authorize('core.contacts.manage');

        $save->delete($client, $contact);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact :email deleted.', ['email' => $contact->email])]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: ?string, email_preferences: list<string>}
     */
    private function payload(ContactRequest $request): array
    {
        return [
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->filled('phone') ? $request->string('phone')->toString() : null,
            'email_preferences' => array_values(array_map('strval', (array) $request->validated('email_preferences'))),
        ];
    }
}
