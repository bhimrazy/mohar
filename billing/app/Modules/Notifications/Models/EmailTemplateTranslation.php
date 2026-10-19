<?php

namespace App\Modules\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $email_template_id
 * @property string $locale
 * @property string $subject
 * @property string|null $preheader
 * @property string|null $heading
 * @property string $body
 * @property string|null $action_label
 * @property string|null $default_hash
 * @property array{subject: string, preheader: string|null, heading: string|null, body: string, action_label: string|null, replaced_at: string}|null $previous_text
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 * @property-read EmailTemplate $template
 */
#[Fillable(['email_template_id', 'locale', 'subject', 'preheader', 'heading', 'body', 'action_label', 'default_hash', 'previous_text', 'updated_by'])]
class EmailTemplateTranslation extends Model
{
    protected function casts(): array
    {
        return ['previous_text' => 'array'];
    }

    /**
     * The editable text as TemplateCatalog hashes and compares it.
     *
     * @return array{subject: string, preheader: string|null, heading: string|null, body: string, action_label: string|null}
     */
    public function text(): array
    {
        return ['subject' => $this->subject, 'preheader' => $this->preheader, 'heading' => $this->heading, 'body' => $this->body, 'action_label' => $this->action_label];
    }

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
