<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Webhooks\WebhookUrlGuard;
use App\Support\Events\WebhookEvents;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notifications.webhooks.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:https,http'],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(['*', ...app(WebhookEvents::class)->types()])],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $url = $this->input('url');

            if (is_string($url) && ! $validator->errors()->has('url') && ($problem = app(WebhookUrlGuard::class)->problemWith($url)) !== null) {
                $validator->errors()->add('url', $problem);
            }
        }];
    }
}
