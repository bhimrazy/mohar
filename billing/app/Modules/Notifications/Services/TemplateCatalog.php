<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;

/**
 * The seeded system templates (Resources/templates.php). Used by the seeder and "restore default text".
 */
class TemplateCatalog
{
    /** @var array<string, array{key: string, type: string, audience: string, name: string, subject: string, body: string}>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, array{key: string, type: string, audience: string, name: string, subject: string, body: string}>
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            /** @var list<array{key: string, type: string, audience: string, name: string, subject: string, body: string}> $list */
            $list = require dirname(__DIR__).'/Resources/templates.php';
            $this->definitions = array_column($list, null, 'key');
        }

        return $this->definitions;
    }

    /**
     * @return array{key: string, type: string, audience: string, name: string, subject: string, body: string}|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Creates missing system templates and their default-locale text; never overwrites staff edits.
     */
    public function install(string $locale): int
    {
        $created = 0;

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
                'body' => $definition['body'],
            ]);

            $created += (int) $translation->wasRecentlyCreated;
        }

        return $created;
    }
}
