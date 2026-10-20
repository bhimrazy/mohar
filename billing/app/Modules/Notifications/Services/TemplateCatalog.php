<?php

namespace App\Modules\Notifications\Services;

use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;

/**
 * The system templates (Resources/templates.php). Installed by the DefaultTemplates reference-data installer;
 * also the source of "restore default text".
 */
class TemplateCatalog
{
    /** @var array<string, array{key: string, type: string, audience: string, name: string, subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}>|null */
    private ?array $definitions = null;

    /** @var array{previous_release: string, fingerprints: array<string, array<string, string>>}|null */
    private ?array $previous = null;

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
     * Creates missing system templates and their default-locale text, and brings text nobody edited up to the new
     * shipped default (an audit entry each). Edited text is never overwritten: the editor offers the new default.
     * Run by app:install, on install and on every upgrade.
     */
    public function install(string $locale): ReferenceDataResult
    {
        $created = $updated = $unchanged = 0;

        foreach ($this->all() as $definition) {
            $template = EmailTemplate::query()->firstOrCreate(['key' => $definition['key']], [
                'type' => TemplateType::from($definition['type']),
                'audience' => TemplateAudience::from($definition['audience']),
                'name' => $definition['name'],
                'is_system' => true,
                'is_enabled' => true,
            ]);
            $shipped = self::shippedText($definition);
            $translation = $template->translations()->firstOrCreate(['locale' => $locale], [...$shipped, 'default_hash' => self::hash($shipped)]);

            if ($translation->wasRecentlyCreated) {
                $created++;
            } elseif ($this->isUnedited($translation, $definition['key']) && self::hash($translation->text()) !== self::hash($shipped)) {
                $translation->update([...$shipped, 'default_hash' => self::hash($shipped)]);
                app(ActivityLogger::class)->log('Email template updated to the new default text', $template, properties: ['key' => $template->key, 'locale' => $locale], event: 'notifications.template_default_updated');
                $updated++;
            } else {
                // Text that matches the shipped default but predates tracking gets its baseline.
                if ($translation->default_hash === null && self::hash($translation->text()) === self::hash($shipped)) {
                    $translation->update(['default_hash' => self::hash($shipped)]);
                }

                $unchanged++;
            }
        }

        return new ReferenceDataResult(created: $created, updated: $updated, unchanged: $unchanged);
    }

    /**
     * Whether staff never changed this text: it still matches the default it started from, or (installs from before
     * defaults were tracked) one of the defaults shipped earlier.
     */
    public function isUnedited(EmailTemplateTranslation $translation, string $key): bool
    {
        $current = self::hash($translation->text());

        return $current === $translation->default_hash || in_array($current, $this->previousDefaults()['fingerprints'][$key] ?? [], true);
    }

    /**
     * Whether the editor offers the current default for this (edited) text: "changed" when the default this text was
     * based on has since changed; "untracked" for text edited before defaults were tracked, when this release changed
     * the template's default (it is listed under the previous release) — the editor then offers a comparison without
     * claiming the text was based on the old wording; null otherwise.
     */
    public function newDefaultStatus(EmailTemplateTranslation $translation, string $key): ?string
    {
        $definition = $this->find($key);

        if ($definition === null) {
            return null;
        }

        $shipped = self::hash(self::shippedText($definition));

        return match (true) {
            self::hash($translation->text()) === $shipped => null,
            $translation->default_hash !== null => $translation->default_hash !== $shipped ? 'changed' : null,
            isset($this->previousDefaults()['fingerprints'][$key][$this->previousDefaults()['previous_release']]) => 'untracked',
            default => null,
        };
    }

    /**
     * @param  array{subject: string, body: string, preheader?: string|null, heading?: string, action_label?: string}  $definition
     * @return array{subject: string, preheader: string|null, heading: string|null, body: string, action_label: string|null}
     */
    public static function shippedText(array $definition): array
    {
        return ['subject' => $definition['subject'], 'preheader' => $definition['preheader'] ?? null, 'heading' => $definition['heading'] ?? null, 'body' => $definition['body'], 'action_label' => $definition['action_label'] ?? null];
    }

    /**
     * A fingerprint of a template text. Only line endings (CRLF or LF) and whitespace at the end of each field are
     * ignored, which editors and browsers add or drop on their own; any other whitespace change counts as an edit.
     *
     * @param  array{subject: string, preheader: string|null, heading: string|null, body: string, action_label: string|null}  $text
     */
    public static function hash(array $text): string
    {
        $normal = array_map(fn (?string $value): string => rtrim(str_replace("\r\n", "\n", (string) $value)), [$text['subject'], $text['preheader'], $text['heading'], $text['body'], $text['action_label']]);

        return hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{previous_release: string, fingerprints: array<string, array<string, string>>}
     */
    private function previousDefaults(): array
    {
        return $this->previous ??= require dirname(__DIR__).'/Resources/previous-defaults.php';
    }
}
