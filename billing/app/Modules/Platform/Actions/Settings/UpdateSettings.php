<?php

namespace App\Modules\Platform\Actions\Settings;

use App\Events\Core\SettingsUpdated;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Modules\Platform\Services\SettingsCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class UpdateSettings
{
    public const MASK = '••••••';

    public function __construct(private SettingsCatalog $catalog, private Settings $settings, private ActivityLogger $activity) {}

    /**
     * Security-policy changes are announced to every active full administrator.
     *
     * @param  list<string>  $keys
     */
    private function notifyFullAdministrators(string $title, array $keys): void
    {
        $admins = User::query()->where('is_staff', true)->where('is_active', true)->with('role')->get()
            ->filter(fn (User $user): bool => $user->role !== null && in_array('*', $user->role->permissions, true));

        Notification::send($admins, new SecurityNotice(__(':title settings were changed', ['title' => $title]), [
            __(':actor changed: :keys.', ['actor' => request()->user()->name ?? __('Someone'), 'keys' => implode(', ', $keys)]),
        ]));
    }

    /**
     * Save changed values of one section; blank secret fields keep their stored value.
     *
     * @param  array<string, mixed>  $input  validated, nested input (e.g. ['core' => ['company_name' => '…']])
     * @return list<string> changed keys
     */
    public function handle(string $section, array $input): array
    {
        $changes = [];
        $diff = [];
        $definitions = $this->catalog->section($section);

        if ($definitions === null) {
            return [];
        }

        foreach ($definitions['settings'] as $definition) {
            if (! Arr::has($input, $definition->key)) {
                continue;
            }

            $raw = Arr::get($input, $definition->key);

            if ($definition->secret && ($raw === null || $raw === '')) {
                continue;
            }

            $value = $this->catalog->cast($definition, $raw);
            $current = $this->settings->get($definition->key);

            if ($value === $current) {
                continue;
            }

            $changes[$definition->key] = $value;
            $diff[$definition->key] = $definition->secret
                ? ['old' => self::MASK, 'new' => self::MASK]
                : ['old' => $current, 'new' => $value];
        }

        if ($changes !== []) {
            DB::transaction(function () use ($changes, $definitions, $diff): void {
                $this->settings->setMany($changes);
                $this->activity->log("{$definitions['title']} settings updated", properties: ['diff' => $diff], event: 'settings.updated');
            });

            SettingsUpdated::dispatch(array_keys($changes));

            if ($this->catalog->requiresConfirmation($section)) {
                $this->notifyFullAdministrators($definitions['title'], array_keys($changes));
            }
        }

        return array_keys($changes);
    }
}
