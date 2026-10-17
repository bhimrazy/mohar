<?php

namespace App\Modules\Themes\Http\Requests;

use App\Support\Themes\ThemeSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a custom theme. Tokens must match the schema exactly and pass the WCAG AA guard.
 */
class ThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('themes.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'base_preset' => ['nullable', 'string', 'max:48'],
            'tokens' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $tokens = $this->input('tokens');

                if (! is_array($tokens) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (strlen((string) json_encode($tokens)) > ThemeSchema::MAX_DOCUMENT_BYTES) {
                    $validator->errors()->add('tokens', 'The theme is too large.');

                    return;
                }

                foreach (ThemeSchema::validate($tokens) as $path => $message) {
                    $validator->errors()->add($path, $message);
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (ThemeSchema::contrastFailures($tokens) as $failure) {
                    $validator->errors()->add(
                        "tokens.{$failure['mode']}.{$failure['foreground']}",
                        sprintf('%s on %s is %.2f:1 in %s mode; WCAG AA needs %.1f:1.', $failure['foreground'], $failure['background'], $failure['ratio'], $failure['mode'], $failure['minimum']),
                    );
                }
            },
        ];
    }

    /**
     * @return array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>}
     */
    public function tokens(): array
    {
        /** @var array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>} */
        return $this->input('tokens');
    }
}
