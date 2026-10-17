<?php

namespace App\Modules\Themes\Http\Requests;

use App\Support\Themes\ThemeSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Import a theme JSON document (as exported). Strict: unknown keys, invalid values,
 * oversize files and contrast failures are all rejected.
 */
class ThemeImportRequest extends FormRequest
{
    /** @var array<string, mixed>|null */
    private ?array $document = null;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('themes.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['file' => ['required', File::types(['json', 'txt'])->max((int) ceil(ThemeSchema::MAX_DOCUMENT_BYTES / 1024))]];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $contents = (string) file_get_contents((string) $this->file('file')?->getRealPath());

                if (strlen($contents) > ThemeSchema::MAX_DOCUMENT_BYTES) {
                    $validator->errors()->add('file', 'The theme file is too large.');

                    return;
                }

                $document = json_decode($contents, true, 8);

                if (! is_array($document)) {
                    $validator->errors()->add('file', 'The file is not valid theme JSON.');

                    return;
                }

                foreach ($this->documentErrors($document) as $message) {
                    $validator->errors()->add('file', $message);
                }

                if ($validator->errors()->isEmpty()) {
                    $this->document = $document;
                }
            },
        ];
    }

    /**
     * @param  array<mixed>  $document
     * @return list<string>
     */
    private function documentErrors(array $document): array
    {
        $allowed = ['schema', 'version', 'key', 'name', 'description', 'base', 'tokens'];
        $errors = [];

        foreach (array_diff(array_keys($document), $allowed) as $unknown) {
            $errors[] = "Unknown key \"{$unknown}\".";
        }

        if (($document['schema'] ?? null) !== ThemeSchema::NAME || ($document['version'] ?? null) !== ThemeSchema::VERSION) {
            $errors[] = 'Unsupported theme schema or version.';
        }

        $name = $document['name'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 60) {
            $errors[] = 'The theme needs a name of up to 60 characters.';
        }

        if (! is_array($document['tokens'] ?? null)) {
            return [...$errors, 'The theme has no tokens.'];
        }

        foreach (ThemeSchema::validate($document['tokens']) as $path => $message) {
            $errors[] = "{$path}: {$message}";
        }

        if ($errors === []) {
            foreach (ThemeSchema::contrastFailures($document['tokens']) as $failure) {
                $errors[] = sprintf('%s on %s is %.2f:1 in %s mode (needs %.1f:1).', $failure['foreground'], $failure['background'], $failure['ratio'], $failure['mode'], $failure['minimum']);
            }
        }

        return array_slice($errors, 0, 20);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->document ?? [];
    }
}
