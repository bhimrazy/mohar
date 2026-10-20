<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->route('client') === null ? 'core.clients.create' : 'core.clients.update') ?? false;
    }

    private function isClosed(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && $client->status === 'closed';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...ClientRules::fields(),
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'client_group_id' => ['nullable', Rule::exists('client_groups', 'id')],
            // Closed (and merged) clients keep their status; lifecycle changes use the close/reopen actions.
            'status' => $this->isClosed() ? ['prohibited'] : ['required', Rule::in(Client::EDITABLE_STATUSES)],
        ];
    }
}
