<?php

namespace App\Modules\Services\Http\Requests;

use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a server. Blank credential fields and blank secret settings on update keep the stored secret.
 */
class ServerRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.servers.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $settings = app(ServerSettings::class);

        $this->merge([
            'settings' => $settings->withDefaults((string) $this->input('driver'), (array) $this->input('settings', [])),
            'hostname' => strtolower(trim((string) $this->input('hostname'))),
            'nameservers' => array_values(array_filter(array_map(fn ($value): string => strtolower(trim((string) $value)), (array) $this->input('nameservers', [])))),
            'assigned_ips' => array_values(array_filter(array_map(fn ($value): string => trim((string) $value), preg_split('/[\s,]+/', (string) $this->input('assigned_ips_text', '')) ?: []))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/'],
            'ip_address' => ['nullable', 'ip'],
            'assigned_ips' => ['array', 'max:256'],
            'assigned_ips.*' => ['ip'],
            'driver' => ['required', 'string', Rule::in(app(ProvisioningDriverRegistry::class)->keys())],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'api_token' => ['nullable', 'string', 'max:4000'],
            'access_hash' => ['nullable', 'string', 'max:10000'],
            'secure' => ['boolean'],
            'port' => ['nullable', 'integer', 'between:1,65535', Rule::notIn($this->refusedPorts())],
            'max_accounts' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'nameservers' => ['array', 'max:5'],
            'nameservers.*' => ['string', 'max:255', 'regex:/^[a-z0-9.-]+$/'],
            'monthly_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'datacenter' => ['nullable', 'string', 'max:255'],
            'status_url' => ['nullable', 'url', 'max:255'],
            ...app(ServerSettings::class)->rules((string) $this->input('driver'), $this->routeServer()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serverData(): array
    {
        $data = $this->safe()->except(['password', 'api_token', 'access_hash', 'settings']);
        $data['settings'] = (array) $this->validated('settings', []);

        foreach (['password', 'api_token', 'access_hash'] as $secret) {
            if ($this->filled($secret)) {
                $data[$secret] = $this->string($secret)->toString();
            }
        }

        return $data;
    }

    /**
     * @return list<int>
     */
    private function refusedPorts(): array
    {
        $drivers = app(ProvisioningDriverRegistry::class);
        $key = (string) $this->input('driver');

        return $drivers->has($key) ? $drivers->metadata($key)->refusedPorts : [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['port.not_in' => __('This port sends credentials without encryption; use the panel\'s TLS port.')];
    }

    /**
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        return ['api_token', 'access_hash', ...$this->settingInputPaths('settings', app(ServerSettings::class)->fields((string) $this->input('driver')))];
    }

    private function routeServer(): ?Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : null;
    }
}
