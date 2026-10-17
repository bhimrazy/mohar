<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Domains\Models\DomainContact;
use Illuminate\Contracts\Validation\ValidationRule;

class ContactsRequest extends DomainAreaRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contacts' => ['required', 'array:'.implode(',', DomainContact::ROLES)],
            'contacts.*.first_name' => ['required', 'string', 'max:100'],
            'contacts.*.last_name' => ['required', 'string', 'max:100'],
            'contacts.*.company' => ['nullable', 'string', 'max:150'],
            'contacts.*.email' => ['required', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.address_1' => ['nullable', 'string', 'max:255'],
            'contacts.*.address_2' => ['nullable', 'string', 'max:255'],
            'contacts.*.city' => ['nullable', 'string', 'max:100'],
            'contacts.*.state' => ['nullable', 'string', 'max:100'],
            'contacts.*.postcode' => ['nullable', 'string', 'max:20'],
            'contacts.*.country' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    public function contacts(): array
    {
        $contacts = [];

        foreach ((array) $this->validated('contacts') as $role => $fields) {
            $fields = array_intersect_key((array) $fields, array_flip(DomainContact::FIELDS));
            $fields['email'] = mb_strtolower((string) $fields['email']);

            if (isset($fields['country'])) {
                $fields['country'] = strtoupper((string) $fields['country']);
            }

            $contacts[(string) $role] = array_map(fn ($value): ?string => $value === null ? null : (string) $value, $fields);
        }

        return $contacts;
    }
}
