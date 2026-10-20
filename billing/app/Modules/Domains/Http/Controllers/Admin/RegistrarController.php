<?php

namespace App\Modules\Domains\Http\Controllers\Admin;

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Modules\Domains\Actions\SaveRegistrarAccount;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Http\Requests\RegistrarAccountRequest;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistrarController extends Controller
{
    public function index(RegistrarManager $registrars): Response
    {
        $this->authorize('domains.registrars.manage');

        $accounts = RegistrarAccount::query()->get()->keyBy('key');
        $domainCounts = Domain::query()->whereNotNull('registrar_key')->selectRaw('registrar_key, count(*) as aggregate')->groupBy('registrar_key')->pluck('aggregate', 'registrar_key');
        $tldCounts = Tld::query()->whereNotNull('registrar_key')->selectRaw('registrar_key, count(*) as aggregate')->groupBy('registrar_key')->pluck('aggregate', 'registrar_key');

        return Inertia::render('admin/domains/registrars/index', [
            'registrars' => array_map(function (string $key) use ($registrars, $accounts, $domainCounts, $tldCounts): array {
                $driver = $registrars->driver($key);
                $account = $accounts->get($key);
                $settings = $account->settings ?? [];
                $schema = $registrars->fields($key);

                return [
                    'key' => $key,
                    'name' => $driver->name(),
                    'capabilities' => $driver->capabilities(),
                    'configured' => $account !== null,
                    'is_active' => $account->is_active ?? true,
                    'test_mode' => $account->test_mode ?? false,
                    'schema' => array_map(fn (SettingField $field): array => $field->toArray(), $schema),
                    'settings' => collect($schema)->mapWithKeys(fn (SettingField $field): array => [
                        $field->key => $field->isSecret() ? '' : ($settings[$field->key] ?? $field->default ?? ($field->type === FieldType::Boolean ? false : null)),
                    ])->all(),
                    'secrets_set' => collect($schema)->filter(fn (SettingField $field): bool => $field->isSecret())->mapWithKeys(fn (SettingField $field): array => [$field->key => filled($settings[$field->key] ?? null)])->all(),
                    'domains_count' => (int) ($domainCounts[$key] ?? 0),
                    'tlds_count' => (int) ($tldCounts[$key] ?? 0),
                ];
            }, $registrars->keys()),
        ]);
    }

    public function update(RegistrarAccountRequest $request, string $registrar, RegistrarManager $registrars, SaveRegistrarAccount $save): RedirectResponse
    {
        abort_unless($registrars->has($registrar), 404);
        $save->handle($registrar, $request->boolean('is_active'), $request->boolean('test_mode'), (array) $request->validated('settings', []));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name settings saved.', ['name' => $registrars->driver($registrar)->name()])]);

        return to_route('admin.domains.registrars.index');
    }

    public function test(string $registrar, RegistrarManager $registrars): RedirectResponse
    {
        $this->authorize('domains.registrars.manage');
        abort_unless($registrars->has($registrar), 404);

        $result = $registrars->driver($registrar)->testConnection();
        Inertia::flash('toast', ['type' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);

        return to_route('admin.domains.registrars.index');
    }
}
