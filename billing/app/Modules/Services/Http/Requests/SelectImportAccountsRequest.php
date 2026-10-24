<?php

namespace App\Modules\Services\Http\Requests;

use App\Contracts\Core\CompanyClock;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\AccountImportPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff choices for accounts of a fetched list (Import accounts, cPanel spec §10): tick or untick, the client, product,
 * cycle and next due date, for given usernames, every proposed account, or every ticked one (bulk cycle and due date).
 * Only active clients the staff member may see and products that provision on the server are accepted. A null value
 * clears a choice: the account goes back to its match, or its default.
 */
class SelectImportAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.servers.import') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->importServer();

        return [
            'proposed' => ['sometimes', 'accepted'],
            'ticked' => ['sometimes', 'accepted'],
            'usernames' => ['required_without_all:proposed,ticked', 'array', 'max:500'],
            'usernames.*' => ['string', 'max:255'],
            'include' => ['sometimes', 'boolean'],
            // The bulk scope sets only the cycle and due date: never one client or product for every ticked account.
            'client_id' => [$this->user()?->hasPermission('core.clients.view') ? 'sometimes' : 'prohibited', 'nullable', 'prohibited_if_accepted:ticked', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'product_id' => ['sometimes', 'nullable', 'prohibited_if_accepted:ticked', 'integer', Rule::in(array_column(app(AccountImportPlan::class)->productOptions($server), 'id'))],
            // Null: back to the account's default (monthly, or the product's first cycle; the next anniversary).
            'cycle' => ['sometimes', 'nullable', Rule::in(array_column(AccountImportPlan::cycles(), 'value'))],
            'next_due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.app(CompanyClock::class)->todayDate()->toDateString(), 'before:+5 years'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.prohibited' => __('Choosing a client needs the permission to view clients.'),
            'client_id.prohibited_if_accepted' => __('Choose the client for each account on its own.'),
            'product_id.prohibited_if_accepted' => __('Choose the product for each account on its own.'),
            'client_id.exists' => __('Choose an active client.'),
            'product_id.in' => __('Choose a product that provisions on :server.', ['server' => $this->importServer()->name]),
            'next_due_date.after_or_equal' => __('The next due date cannot be in the past.'),
        ];
    }

    /**
     * @return 'usernames'|'proposed'|'ticked'
     */
    public function scope(): string
    {
        return match (true) {
            $this->has('proposed') => 'proposed',
            $this->has('ticked') => 'ticked',
            default => 'usernames',
        };
    }

    /**
     * What to record for the accounts in scope.
     *
     * @return array<string, mixed>
     */
    public function choice(): array
    {
        $data = $this->validated();

        return match ($this->scope()) {
            'proposed' => ['include' => true],
            'ticked' => array_intersect_key($data, array_flip(['cycle', 'next_due_date'])),
            default => array_intersect_key($data, array_flip(['include', 'client_id', 'product_id', 'cycle', 'next_due_date'])),
        };
    }

    private function importServer(): Server
    {
        /** @var Server */
        return $this->route('server');
    }
}
