<?php

namespace App\Modules\Support\Rules;

use App\Contracts\Services\ClientServiceDirectory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A related service must belong to the ticket's client (spec §4.1), checked through the Services
 * module's ClientServiceDirectory contract.
 */
class ServiceOfClient implements ValidationRule
{
    public function __construct(private ?int $clientId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($this->clientId === null) {
            $fail(__('Link the ticket to a client before choosing a related service.'));

            return;
        }

        if (! is_numeric($value) || ! app(ClientServiceDirectory::class)->belongsToClient((int) $value, $this->clientId)) {
            $fail(__('Choose one of the client\'s services.'));
        }
    }
}
