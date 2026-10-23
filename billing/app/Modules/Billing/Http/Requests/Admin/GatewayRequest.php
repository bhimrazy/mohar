<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Data\Integrations\SettingField;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use App\Support\Integrations\SecretMinimumLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GatewayRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

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
        ];
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
        return $this->settingInputPaths('settings', $this->fields());
    }
}
