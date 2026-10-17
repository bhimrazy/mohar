<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Core\Support\ClientContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for requests shared by the admin and client areas: staff need domains.domains.update,
 * client users need the "domains" area permission of the current account.
 */
abstract class DomainAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isAdminArea()) {
            return $this->user()?->hasPermission('domains.domains.update') ?? false;
        }

        return app(ClientContext::class)->can('domains');
    }

    protected function isAdminArea(): bool
    {
        return str_starts_with((string) $this->route()?->getName(), 'admin.');
    }
}
