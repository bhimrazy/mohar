<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Support\Integrations\CertificateBundle;
use App\Support\Integrations\CertificateRefused;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use App\Support\Integrations\SecretMinimumLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GatewayRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

    /** @var array<string, CertificateBundle> certificates read from this request's uploads, by setting key */
    private array $certificates = [];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.gateways.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'show_on_order_form' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'convert_to_currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:5000'],
            'replacement' => ['nullable', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            ...collect($this->fields())->filter(fn (SettingField $field): bool => $field->isSecret())
                ->mapWithKeys(fn (SettingField $field): array => ["settings.{$field->key}" => ['nullable', 'string', 'max:5000', new SecretMinimumLength($field)]])
                ->all(),
            ...collect($this->certificateFields())->flatMap(fn (SettingField $field): array => [
                "certificates.{$field->key}.file" => ['nullable', 'file', 'max:'.(CertificateBundle::MAX_BYTES / 1024)],
                "certificates.{$field->key}.passphrase" => ['nullable', 'string', 'max:1000'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect($this->certificateFields())->flatMap(fn (SettingField $field): array => [
            "certificates.{$field->key}.file" => $field->label,
            "certificates.{$field->key}.passphrase" => __(':field passphrase', ['field' => $field->label]),
        ])->all();
    }

    /**
     * Reads each uploaded certificate with its passphrase in memory; a file OpenSSL refuses fails validation at the
     * field, with the reason.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->certificateFields() as $field) {
                $file = $this->file("certificates.{$field->key}.file");

                if (! $file instanceof UploadedFile) {
                    continue;
                }

                try {
                    $this->certificates[$field->key] = CertificateBundle::fromPkcs12((string) $file->get(), (string) $this->input("certificates.{$field->key}.passphrase", ''));
                } catch (CertificateRefused $refused) {
                    $validator->errors()->add("certificates.{$field->key}.file", $refused->getMessage());
                }
            }
        }];
    }

    /**
     * Certificates uploaded with this save, read and checked.
     *
     * @return array<string, CertificateBundle>
     */
    public function certificates(): array
    {
        return $this->certificates;
    }

    /**
     * @return list<SettingField>
     */
    private function certificateFields(): array
    {
        return array_values(array_filter($this->fields(), fn (SettingField $field): bool => $field->type === FieldType::Certificate));
    }

    /**
     * @return list<SettingField>
     */
    private function fields(): array
    {
        $key = (string) $this->route('gateway');

        return array_key_exists($key, app(GatewayManager::class)->drivers())
            ? array_map(SettingField::fromArray(...), app(GatewayManager::class)->driver($key)->settingsFields())
            : [];
    }

    /**
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        return [...$this->settingInputPaths('settings', $this->fields()), 'certificates'];
    }
}
