<?php

namespace App\Modules\Billing\Http\Requests\Client;

use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A client accepts a quote; the terms must be ticked when the company requires it.
 */
class AcceptQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ClientContext::class)->authorize('billing');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return app(BillingSettings::class)->bool('quotes.require_tos') ? ['accept_tos' => ['accepted']] : [];
    }
}
