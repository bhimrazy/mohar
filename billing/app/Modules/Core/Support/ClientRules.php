<?php

namespace App\Modules\Core\Support;

/**
 * The field rules a client record must pass, shared by the admin client form and client import (client-import spec
 * §5.4), so the two can never disagree. They never query the database: the form adds its existence checks, and the
 * import resolves currencies and groups once per chunk.
 */
final class ClientRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function fields(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'tax_exempt' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
