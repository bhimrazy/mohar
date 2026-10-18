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
 * @property string $body
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 * @property-read EmailTemplate $template
 */
#[Fillable(['email_template_id', 'locale', 'subject', 'body', 'updated_by'])]
class EmailTemplateTranslation extends Model
{
    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
