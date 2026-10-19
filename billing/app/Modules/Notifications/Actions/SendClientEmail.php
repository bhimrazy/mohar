<?php

namespace App\Modules\Notifications\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\MergeContext;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * An explicit staff send to the account's primary address — preferences do not apply (spec §7 "always").
 */
class SendClientEmail
{
    public function __construct(
        private NotificationMailer $mailer,
        private RecipientResolver $recipients,
        private MergeContext $context,
        private EmailComposer $composer,
    ) {}

    /**
     * @return int messages queued
     */
    public function handle(Client $client, ?string $templateKey, ?string $subject, ?string $body): int
    {
        $template = $templateKey !== null
            ? EmailTemplate::query()->with('translations')->where('key', $templateKey)->where('audience', TemplateAudience::Client)->firstOrFail()
            : $this->adHoc((string) $subject, (string) $body);

        $request = new EmailRequest(
            $template->key,
            [$this->recipients->owner($client)],
            $this->context->client($client->id),
            'manual|'.Str::uuid(),
            $client->id,
            'client',
            $client->id,
        );

        return count($this->mailer->sendTemplate($template, $request));
    }

    private function adHoc(string $subject, string $body): EmailTemplate
    {
        $template = new EmailTemplate(['key' => 'adhoc', 'type' => TemplateType::General, 'audience' => TemplateAudience::Client, 'name' => Str::limit($subject, 100), 'is_enabled' => true, 'plain_text_only' => false]);
        $template->setRelation('translations', new Collection([new EmailTemplateTranslation(['locale' => $this->composer->defaultLocale(), 'subject' => $subject, 'body' => $body])]));

        return $template;
    }
}
