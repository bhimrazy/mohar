<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Themes\Http\Requests\ThemeImportRequest;
use App\Modules\Themes\Http\Requests\ThemeRequest;
use App\Modules\Themes\Models\Theme;
use App\Modules\Themes\Support\ThemeManager;
use App\Support\Themes\ThemeRegistry;
use App\Support\Themes\ThemeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Custom themes: duplicate a preset or theme, edit tokens with live preview, save, export/import.
 */
class ThemeController extends Controller
{
    public function __construct(
        private ThemeManager $themes,
        private ThemeRegistry $registry,
        private ActivityLogger $activity,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('themes.manage');

        $source = $this->themes->find($request->string('from', ThemeManager::FALLBACK)->toString())
            ?? $this->themes->resolve('admin');

        return $this->editor(null, "{$source->name} (custom)", $source->custom ? null : $source->key, $source->tokens);
    }

    public function store(ThemeRequest $request): RedirectResponse
    {
        $theme = Theme::query()->create([
            'key' => $this->newKey($request->string('name')->toString()),
            'name' => $request->string('name')->trim()->toString(),
            'base_preset' => $this->presetKey($request->input('base_preset')),
            'tokens' => $request->tokens(),
            'version' => 1,
            'created_by' => $request->user()?->id,
        ]);

        $this->activity->log('Custom theme created', $theme, properties: ['key' => $theme->key, 'name' => $theme->name, 'base' => $theme->base_preset], event: 'themes.theme_created');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme ":name" saved.', ['name' => $theme->name])]);

        return to_route('admin.appearance.themes.edit', $theme);
    }

    public function edit(Theme $theme): Response
    {
        $this->authorize('themes.manage');

        return $this->editor($theme, $theme->name, $theme->base_preset, $theme->tokens);
    }

    public function update(ThemeRequest $request, Theme $theme): RedirectResponse
    {
        $before = $theme->tokens;
        $oldName = $theme->name;
        $theme->update([
            'name' => $request->string('name')->trim()->toString(),
            'tokens' => $request->tokens(),
            'version' => $theme->version + 1,
        ]);
        $this->activity->log('Custom theme updated', $theme, properties: [
            'key' => $theme->key,
            'version' => $theme->version,
            'name' => $oldName === $theme->name ? $theme->name : ['from' => $oldName, 'to' => $theme->name],
            'changes' => $this->tokenDiff($before, $theme->tokens),
        ], event: 'themes.theme_updated');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme ":name" saved.', ['name' => $theme->name])]);

        return to_route('admin.appearance.themes.edit', $theme);
    }

    public function rename(Request $request, Theme $theme): RedirectResponse
    {
        $this->authorize('themes.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $oldName = $theme->name;
        $theme->update(['name' => trim($data['name'])]);
        $this->activity->log('Custom theme renamed', $theme, properties: ['key' => $theme->key, 'from' => $oldName, 'to' => $theme->name], event: 'themes.theme_renamed');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme renamed.')]);

        return back();
    }

    public function destroy(Theme $theme): RedirectResponse
    {
        $this->authorize('themes.manage');

        $usages = $this->themes->usages($theme->key);

        if ($usages !== []) {
            return back()->withErrors(['theme' => __(':name is in use (:where). Choose another theme there first.', [
                'name' => $theme->name,
                'where' => implode(', ', $usages),
            ])]);
        }

        $theme->delete();
        $this->activity->log('Custom theme deleted', properties: ['key' => $theme->key, 'name' => $theme->name], event: 'themes.theme_deleted');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme ":name" deleted.', ['name' => $theme->name])]);

        return to_route('admin.appearance.edit');
    }

    public function export(string $key): JsonResponse
    {
        $this->authorize('themes.manage');

        $theme = $this->themes->find($key) ?? abort(404);

        return response()->json([
            'schema' => ThemeSchema::NAME,
            'version' => ThemeSchema::VERSION,
            'name' => $theme->name,
            'description' => $theme->description,
            'tokens' => $theme->tokens,
        ], 200, [
            'Content-Disposition' => 'attachment; filename="theme-'.$theme->key.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function import(ThemeImportRequest $request): RedirectResponse
    {
        $document = $request->document();
        $name = trim((string) $document['name']);

        $theme = Theme::query()->create([
            'key' => $this->newKey($name),
            'name' => $name,
            'base_preset' => $this->presetKey($document['base'] ?? null),
            'tokens' => $document['tokens'],
            'version' => 1,
            'created_by' => $request->user()?->id,
        ]);

        $this->activity->log('Custom theme imported', $theme, properties: ['key' => $theme->key, 'name' => $theme->name], event: 'themes.theme_imported');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme ":name" imported.', ['name' => $theme->name])]);

        return to_route('admin.appearance.themes.edit', $theme);
    }

    public function schema(): JsonResponse
    {
        return response()->json(ThemeSchema::jsonSchema(), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<mixed>  $tokens
     */
    private function editor(?Theme $theme, string $name, ?string $base, array $tokens): Response
    {
        return Inertia::render('admin/settings/theme-editor', [
            'theme' => $theme === null ? null : ['id' => $theme->id, 'key' => $theme->key, 'version' => $theme->version, 'usages' => $this->themes->usages($theme->key)],
            'name' => $name,
            'basePreset' => $base,
            'tokens' => $tokens,
            'presets' => array_values(array_map(fn ($preset): array => ['key' => $preset->key, 'name' => $preset->name, 'tokens' => $preset->tokens], $this->registry->all())),
            'schema' => ThemeSchema::describe(),
        ]);
    }

    /**
     * Changed tokens as "group.token" => [from, to] (capped for the audit log).
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function tokenDiff(array $before, array $after): array
    {
        $changes = [];

        foreach (['shared', 'light', 'dark'] as $group) {
            $old = is_array($before[$group] ?? null) ? $before[$group] : [];
            $new = is_array($after[$group] ?? null) ? $after[$group] : [];

            foreach ($new as $token => $value) {
                if (($old[$token] ?? null) !== $value) {
                    $changes["{$group}.{$token}"] = ['from' => $old[$token] ?? null, 'to' => $value];
                }
            }
        }

        return array_slice($changes, 0, 40, true);
    }

    private function newKey(string $name): string
    {
        $slug = Str::limit(Str::slug($name), 30, '') ?: 'theme';

        do {
            $key = 'custom-'.trim($slug, '-').'-'.Str::lower(Str::random(4));
        } while (Theme::query()->where('key', $key)->exists());

        return $key;
    }

    private function presetKey(mixed $key): ?string
    {
        return is_string($key) && $this->registry->has($key) ? $key : null;
    }
}
