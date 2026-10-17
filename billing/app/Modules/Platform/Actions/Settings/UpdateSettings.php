<?php

namespace App\Modules\Platform\Actions\Settings;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Services\SettingsCatalog;
use Illuminate\Support\Arr;

class UpdateSettings
{
    public const MASK = '••••••';

    public function __construct(private SettingsCatalog $catalog, private Settings $settings, private ActivityLogger $activity) {}

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
            $this->settings->setMany($changes);
            $this->activity->log("{$definitions['title']} settings updated", properties: ['diff' => $diff], event: 'settings.updated');
        }

        return array_keys($changes);
    }
}
