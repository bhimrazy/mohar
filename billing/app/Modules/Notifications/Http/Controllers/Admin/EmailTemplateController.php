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
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        private TemplateCatalog $catalog,
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

        $locale = $this->composer->defaultLocale();

        return Inertia::render('admin/notifications/templates/index', [
            'templates' => $templates->map(fn (EmailTemplate $template): array => [
                'id' => $template->id,
                'key' => $template->key,
                'name' => $template->name,
                'type' => $template->type->value,
                'audience' => $template->audience->value,
                'is_system' => $template->is_system,
                'is_enabled' => $template->is_enabled,
                'description' => $this->catalog->find($template->key)['description'] ?? null,
                'subject' => $template->translationFor($locale, $locale)?->subject,
                // Merge fields filled with sample data, as the editor preview does.
                'sample_subject' => $this->composer->sampleSubject($template, $locale, $this->sampleFor($template)),
                'locales' => $template->translations->pluck('locale')->sort()->values()->all(),
                'updated_at' => $template->translations->max('updated_at')?->toIso8601String(),
            ])->values(),
            'types' => $this->typeOptions(TemplateType::cases()),
            'filters' => $request->only(['type', 'search']),
            'canManage' => $request->user()?->hasPermission('notifications.templates.manage') ?? false,
        ]);
    }

    /** @var array<string, array<string, mixed>> */
    private array $sampleCache = [];

    /**
     * Templates whose sample data is identical share one build.
     *
     * @return array<string, mixed>
     */
    private function sampleFor(EmailTemplate $template): array
    {
        $group = $template->audience->value.(str_starts_with($template->key, 'invoice.overdue') ? ':overdue' : '').(str_contains($template->key, 'invitation') ? ':invitation' : '');

        return $this->sampleCache[$group] ??= $this->samples->build($template->audience === TemplateAudience::Staff, $template->key);
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
        $this->activity->log('Email template created', $template, properties: ['key' => $template->key, 'delivery' => $template->only(['from_name', 'from_email', 'cc', 'bcc'])]);
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
        $before = $template->only(['from_name', 'from_email', 'cc', 'bcc']);
        $template->update($this->templateAttributes($request));
        $this->saveTranslation($template, $request);
        $this->activity->log('Email template updated', $template, properties: ['key' => $template->key, 'locale' => $request->string('locale')->toString()]);

        if ($before !== $template->only(['from_name', 'from_email', 'cc', 'bcc'])) {
            $this->activity->log('Email template sender or copies changed', $template, properties: ['key' => $template->key, 'old' => $before, 'new' => $template->only(['from_name', 'from_email', 'cc', 'bcc'])]);
        }
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
        abort_if($template->isAlwaysSent(), 422, __('Account and security emails are always sent.'));
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

        $current = $template->translations()->where('locale', $this->composer->defaultLocale())->first();
        $shipped = TemplateCatalog::shippedText($definition);
        // "Use new default" keeps the staff text it replaces (shown in the editor and in the activity log).
        $replacesEdit = $current !== null && TemplateCatalog::hash($current->text()) !== TemplateCatalog::hash($shipped);
        $previous = $replacesEdit ? [...$current->text(), 'replaced_at' => now()->toIso8601String()] : $current?->previous_text;

        $template->translations()->updateOrCreate(
            ['locale' => $this->composer->defaultLocale()],
            [...$shipped, 'default_hash' => TemplateCatalog::hash($shipped), 'previous_text' => $previous, 'updated_by' => $request->user()?->id],
        );
        $this->activity->log('Email template restored to default text', $template, properties: ['key' => $template->key, 'previous' => $previous]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $replacesEdit
            ? __('New default text in use. Your previous text is kept.')
            : __('Default text restored.')]);

        return to_route('admin.notifications.templates.edit', $template);
    }

    /**
     * Renders unsaved editor text against sample data (fake, non-functional links), through the same gate and
     * renderer as real sends.
     */
    public function preview(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('notifications.templates.view');
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'action_label' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);
        $locale = (string) ($validated['locale'] ?? $this->composer->defaultLocale());

        $problems = SecretTemplatePolicy::violations($template->key, Arr::only($validated, ['subject', 'preheader', 'heading', 'body', 'action_label']));

        if ($problems !== []) {
            return response()->json(['error' => implode(' ', array_merge(...array_values($problems))), 'warnings' => [], 'subject' => null, 'html' => null, 'text' => null]);
        }

        $draft = $template->replicate();
        $draft->key = $template->key;
        $draft->setRelation('translations', new Collection([new EmailTemplateTranslation([
            'locale' => $locale,
            'subject' => $validated['subject'],
            'preheader' => $validated['preheader'] ?? null,
            'heading' => $validated['heading'] ?? null,
            'body' => $validated['body'],
            'action_label' => $validated['action_label'] ?? null,
        ])]));

        try {
            $composed = $this->composer->compose($draft, $locale, $this->samples->build($template->audience === TemplateAudience::Staff, $template->key));
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
            $this->samples->build($template->audience === TemplateAudience::Staff, $template->key),
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
                'always_sent' => $template->isAlwaysSent(),
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
                'preheader' => $translation->preheader ?? '',
                'heading' => $translation->heading ?? '',
                'body' => $translation->body ?? '',
                'action_label' => $translation->action_label ?? '',
                'updated_at' => $translation?->updated_at?->toIso8601String(),
                'previous_text' => $translation?->previous_text,
            ],
            // Edited system text whose shipped default changed since: offered side by side ("Use new default").
            'newDefault' => $template !== null && $template->is_system && $translation !== null && $translation->locale === $this->composer->defaultLocale()
                && ($definition = $this->catalog->find($template->key)) !== null && ($status = $this->catalog->newDefaultStatus($translation, $template->key)) !== null
                ? ['status' => $status, 'text' => TemplateCatalog::shippedText($definition)]
                : null,
            'defaultLocale' => $this->composer->defaultLocale(),
            'fields' => $template !== null && $template->carriesSecret() ? ['allowed' => SecretTemplatePolicy::ALLOWED_FIELDS] : $this->samples->fieldsFor($type),
            'types' => $this->typeOptions(TemplateType::customisable()),
            'canManage' => request()->user()?->hasPermission('notifications.templates.manage') ?? false,
            'canManageDelivery' => request()->user()?->hasPermission('notifications.delivery.manage') ?? false,
            'secretAction' => $template !== null && $template->carriesSecret() ? (SecretTemplatePolicy::ACTION_LINKS[$template->key] ?? null) : null,
            // Client ticket mail: the system adds the ticket button; only its label is editable (plain text).
            'systemAction' => $template !== null && SecretTemplatePolicy::systemActionPath($template->key) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateAttributes(EmailTemplateRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            // Account and security mail can't be switched off.
            'is_enabled' => $request->route('template') instanceof EmailTemplate && $request->route('template')->isAlwaysSent() ? true : $request->boolean('is_enabled', true),
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
            'preheader' => $request->filled('preheader') ? $request->string('preheader')->toString() : null,
            'heading' => $template->carriesSecret() ? $request->string('heading')->toString() : null,
            'body' => $request->string('body')->toString(),
            'action_label' => $template->carriesSecret() || SecretTemplatePolicy::systemActionPath($template->key) !== null ? $request->string('action_label')->toString() : null,
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
