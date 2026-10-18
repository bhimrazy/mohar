<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Http\Requests\EmailTemplateRequest;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template list, per-locale editor with merge-field sidebar, preview, test send, enable/disable,
 * restore default text and custom templates (spec §9).
 */
class EmailTemplateController extends Controller
{
    public function __construct(
        private SampleContext $samples,
        private EmailComposer $composer,
        private ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('notifications.templates.view');

        $templates = EmailTemplate::query()->with('translations:id,email_template_id,locale,subject,updated_at')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$request->string('search')->toString().'%')
                ->orWhereLike('key', '%'.$request->string('search')->toString().'%')))
            ->orderBy('type')->orderBy('name')->orderBy('id')->get();

        return Inertia::render('admin/notifications/templates/index', [
            'templates' => $templates->map(fn (EmailTemplate $template): array => [
                'id' => $template->id,
                'key' => $template->key,
                'name' => $template->name,
                'type' => $template->type->value,
                'audience' => $template->audience->value,
                'is_system' => $template->is_system,
                'is_enabled' => $template->is_enabled,
                'subject' => $template->translationFor($this->composer->defaultLocale(), $this->composer->defaultLocale())?->subject,
                'locales' => $template->translations->pluck('locale')->sort()->values()->all(),
                'updated_at' => $template->translations->max('updated_at')?->toIso8601String(),
            ])->values(),
            'types' => $this->typeOptions(TemplateType::cases()),
            'filters' => $request->only(['type', 'search']),
            'canManage' => $request->user()?->hasPermission('notifications.templates.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('notifications.templates.manage');

        return Inertia::render('admin/notifications/templates/edit', $this->editorProps(null, $this->composer->defaultLocale()));
    }

    public function store(EmailTemplateRequest $request): RedirectResponse
    {
        $template = EmailTemplate::query()->create([
            ...$this->templateAttributes($request),
            'key' => $request->string('key')->toString(),
            'type' => TemplateType::from($request->string('type')->toString()),
            'audience' => TemplateAudience::Client,
            'is_system' => false,
        ]);
        $this->saveTranslation($template, $request);
        $this->activity->log('Email template created', $template, properties: ['key' => $template->key]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template ":name" created.', ['name' => $template->name])]);

        return to_route('admin.notifications.templates.edit', $template);
    }

    public function edit(Request $request, EmailTemplate $template): Response
    {
        $this->authorize('notifications.templates.view');
        $locale = $request->string('locale')->toString() ?: $this->composer->defaultLocale();

        return Inertia::render('admin/notifications/templates/edit', $this->editorProps($template, preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale) ? $locale : $this->composer->defaultLocale()));
    }

    public function update(EmailTemplateRequest $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($this->templateAttributes($request));
        $this->saveTranslation($template, $request);
        $this->activity->log('Email template updated', $template, properties: ['key' => $template->key, 'locale' => $request->string('locale')->toString()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template ":name" saved.', ['name' => $template->name])]);

        return to_route('admin.notifications.templates.edit', [$template, 'locale' => $request->string('locale')->toString()]);
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        $this->authorize('notifications.templates.manage');
        abort_if($template->is_system, 403, __('System templates cannot be deleted; disable them instead.'));

        $template->delete();
        $this->activity->log('Email template deleted', properties: ['key' => $template->key]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template ":name" deleted.', ['name' => $template->name])]);

        return to_route('admin.notifications.templates.index');
    }

    public function toggle(EmailTemplate $template): RedirectResponse
    {
        $this->authorize('notifications.templates.manage');
        $template->update(['is_enabled' => ! $template->is_enabled]);
        $this->activity->log($template->is_enabled ? 'Email template enabled' : 'Email template disabled', $template, properties: ['key' => $template->key]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $template->is_enabled ? __('Template enabled.') : __('Template disabled — these emails are logged as suppressed.')]);

        return back();
    }

    public function restore(Request $request, EmailTemplate $template, TemplateCatalog $catalog): RedirectResponse
    {
        $this->authorize('notifications.templates.manage');
        $definition = $catalog->find($template->key);
        abort_if(! $template->is_system || $definition === null, 404);

        $template->translations()->updateOrCreate(
            ['locale' => $this->composer->defaultLocale()],
            ['subject' => $definition['subject'], 'body' => $definition['body'], 'updated_by' => $request->user()?->id],
        );
        $this->activity->log('Email template restored to default text', $template, properties: ['key' => $template->key]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Default text restored.')]);

        return to_route('admin.notifications.templates.edit', $template);
    }

    /**
     * Renders unsaved editor text against sample data (or reports the syntax error).
     */
    public function preview(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('notifications.templates.view');
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $composed = $this->composer->composeSource(
                $template->hasFixedSubject() ? EmailComposer::FIXED_SUPPORT_SUBJECT : $validated['subject'],
                $validated['body'],
                $this->samples->build($template->audience === TemplateAudience::Staff),
                (string) ($validated['locale'] ?? $this->composer->defaultLocale()),
                $template->plain_text_only,
            );
        } catch (TemplateSyntaxError $error) {
            return response()->json(['error' => $error->getMessage(), 'warnings' => [], 'subject' => null, 'html' => null, 'text' => null]);
        }

        return response()->json([
            'error' => null,
            'warnings' => $composed->warnings,
            'subject' => $composed->subject,
            'html' => $composed->html,
            'text' => $composed->text,
        ]);
    }

    /**
     * Sends the saved template, rendered with sample data, to the signed-in staff member.
     */
    public function test(Request $request, EmailTemplate $template, NotificationMailer $mailer): RedirectResponse
    {
        $this->authorize('notifications.templates.view');
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $mailer->send(new EmailRequest(
            $template->key,
            [new Recipient($user->email, $user->name, staffId: $user->id)],
            $this->samples->build($template->audience === TemplateAudience::Staff),
            'test|'.Str::uuid(),
            relatedType: 'email_template',
            relatedId: $template->id,
        ));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent to :email.', ['email' => $user->email])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function editorProps(?EmailTemplate $template, string $locale): array
    {
        $translation = $template?->translationFor($locale, $this->composer->defaultLocale());
        $type = $template->type ?? TemplateType::General;

        return [
            'template' => $template === null ? null : [
                'id' => $template->id,
                'key' => $template->key,
                'name' => $template->name,
                'type' => $template->type->value,
                'audience' => $template->audience->value,
                'is_system' => $template->is_system,
                'is_enabled' => $template->is_enabled,
                'from_name' => $template->from_name,
                'from_email' => $template->from_email,
                'cc' => implode(', ', $template->cc ?? []),
                'bcc' => implode(', ', $template->bcc ?? []),
                'plain_text_only' => $template->plain_text_only,
                'fixed_subject' => $template->hasFixedSubject(),
                'locales' => $template->translations()->orderBy('locale')->pluck('locale')->all(),
            ],
            'translation' => [
                'locale' => $locale,
                'exists' => $translation !== null && $translation->locale === $locale,
                'subject' => $template?->hasFixedSubject() ? EmailComposer::FIXED_SUPPORT_SUBJECT : ($translation->subject ?? ''),
                'body' => $translation->body ?? '',
                'updated_at' => $translation?->updated_at?->toIso8601String(),
            ],
            'defaultLocale' => $this->composer->defaultLocale(),
            'fields' => $this->samples->fieldsFor($type),
            'types' => $this->typeOptions(TemplateType::customisable()),
            'canManage' => request()->user()?->hasPermission('notifications.templates.manage') ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateAttributes(EmailTemplateRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'is_enabled' => $request->boolean('is_enabled', true),
            'plain_text_only' => $request->boolean('plain_text_only'),
            'from_name' => $request->filled('from_name') ? $request->string('from_name')->toString() : null,
            'from_email' => $request->filled('from_email') ? $request->string('from_email')->toString() : null,
            'cc' => $request->array('cc') ?: null,
            'bcc' => $request->array('bcc') ?: null,
        ];
    }

    private function saveTranslation(EmailTemplate $template, EmailTemplateRequest $request): void
    {
        $template->translations()->updateOrCreate(['locale' => $request->string('locale')->toString()], [
            // Support subjects are fixed for threading; the stored subject is kept as-is.
            'subject' => $template->hasFixedSubject() ? EmailComposer::FIXED_SUPPORT_SUBJECT : $request->string('subject')->toString(),
            'body' => $request->string('body')->toString(),
            'updated_by' => $request->user()?->id,
        ]);
    }

    /**
     * @param  list<TemplateType>  $types
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(array $types): array
    {
        return array_map(fn (TemplateType $type): array => ['value' => $type->value, 'label' => $type->label()], $types);
    }
}
