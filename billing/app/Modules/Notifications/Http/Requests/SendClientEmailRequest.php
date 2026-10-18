<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Manual send to a client (spec §9): a client-audience template, or an ad-hoc subject + body.
 */
class SendClientEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notifications.send') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Templates carrying one-time links are only sent by their own flows, never by hand.
            'template_key' => ['nullable', 'string', Rule::exists('email_templates', 'key')->where('audience', 'client'), Rule::notIn(EmailTemplate::SECRET_TEMPLATES)],
            'subject' => ['required_without:template_key', 'nullable', 'string', 'max:255'],
            'body' => ['required_without:template_key', 'nullable', 'string', 'max:50000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['subject', 'body'] as $field) {
                if (is_string($source = $this->input($field)) && $source !== '') {
                    try {
                        app(TemplateEngine::class)->validate($source);
                    } catch (TemplateSyntaxError $error) {
                        $validator->errors()->add($field, $error->getMessage());
                    }
                }
            }
        }];
    }
}
