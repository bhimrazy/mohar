<?php

namespace App\Modules\Notifications\Services;

use App\Data\Install\ReferenceDataResult;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;

/**
 * The system templates (Resources/templates.php). Installed by the DefaultTemplates reference-data installer;
 * also the source of "restore default text".
 */
class TemplateCatalog
{
    /** @var array<string, array{key: string, type: string, audience: string, name: string, subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, array{key: string, type: string, audience: string, name: string, subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}>
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            /** @var list<array{key: string, type: string, audience: string, name: string, subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}> $list */
            $list = require dirname(__DIR__).'/Resources/templates.php';
            $this->definitions = array_column($list, null, 'key');
        }

        return $this->definitions;
    }

    /**
     * @return array{key: string, type: string, audience: string, name: string, subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Creates missing system templates and their default-locale text; never overwrites staff edits.
     * Counts template texts created, and those already present (left as they are).
     */
    public function install(string $locale): ReferenceDataResult
    {
        $created = 0;
        $unchanged = 0;

        foreach ($this->all() as $definition) {
            $template = EmailTemplate::query()->firstOrCreate(['key' => $definition['key']], [
                'type' => TemplateType::from($definition['type']),
                'audience' => TemplateAudience::from($definition['audience']),
                'name' => $definition['name'],
                'is_system' => true,
                'is_enabled' => true,
            ]);

            $translation = $template->translations()->firstOrCreate(['locale' => $locale], [
                'subject' => $definition['subject'],
                'preheader' => $definition['preheader'] ?? null,
                'heading' => $definition['heading'] ?? null,
                'body' => $definition['body'],
                'action_label' => $definition['action_label'] ?? null,
            ]);

            $translation->wasRecentlyCreated ? $created++ : $unchanged++;
        }

        return new ReferenceDataResult(created: $created, unchanged: $unchanged);
    }
}
