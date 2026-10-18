<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (custom) or update a template and the text of one locale. Syntax errors block saving (spec §3).
 */
class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notifications.templates.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['cc', 'bcc'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => array_values(array_filter(array_map(fn (string $email): string => mb_strtolower(trim($email)), explode(',', $this->string($field)->toString()))))]);
            }
        }

        if (is_string($this->input('from_email')) && $this->input('from_email') !== '') {
            $this->merge(['from_email' => mb_strtolower(trim($this->string('from_email')->toString()))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->route('template') instanceof EmailTemplate;

        return [
            'key' => $creating ? ['required', 'string', 'max:120', 'regex:/^custom\.[a-z0-9_]+(\.[a-z0-9_]+)*$/', Rule::unique('email_templates', 'key')] : ['prohibited'],
            'type' => $creating ? ['required', Rule::in(array_map(fn (TemplateType $type): string => $type->value, TemplateType::customisable()))] : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'is_enabled' => ['boolean'],
            'plain_text_only' => ['boolean'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'cc' => ['array', 'max:10'],
            'cc.*' => ['email', 'max:255'],
            'bcc' => ['array', 'max:10'],
            'bcc.*' => ['email', 'max:255'],
            'locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}([_-][A-Za-z]{2})?$/'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->changesDelivery() && ! ($this->user()?->hasPermission('notifications.delivery.manage') ?? false)) {
                $validator->errors()->add('bcc', __('Changing the sender, CC or BCC needs the "Change email senders, Reply-To, global BCC and template CC/BCC" permission.'));
            }

            $engine = app(TemplateEngine::class);

            foreach (['subject', 'body'] as $field) {
                $source = $this->input($field);

                if (! is_string($source)) {
                    continue;
                }

                try {
                    $engine->validate($source);
                } catch (TemplateSyntaxError $error) {
                    $validator->errors()->add($field, $error->getMessage());
                }
            }
        }];
    }

    /**
     * Whether the request sets the sender or copies (CC/BCC) differently from the stored template.
     */
    public function changesDelivery(): bool
    {
        $template = $this->route('template');
        $current = $template instanceof EmailTemplate
            ? ['from_name' => $template->from_name, 'from_email' => $template->from_email, 'cc' => $template->cc ?? [], 'bcc' => $template->bcc ?? []]
            : ['from_name' => null, 'from_email' => null, 'cc' => [], 'bcc' => []];
        $requested = [
            'from_name' => $this->filled('from_name') ? (string) $this->input('from_name') : null,
            'from_email' => $this->filled('from_email') ? (string) $this->input('from_email') : null,
            'cc' => array_values((array) $this->input('cc', [])),
            'bcc' => array_values((array) $this->input('bcc', [])),
        ];

        return $requested !== $current;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['key.regex' => 'Custom template keys start with "custom." and use lowercase letters, digits and dots, e.g. custom.maintenance_notice.'];
    }
}
