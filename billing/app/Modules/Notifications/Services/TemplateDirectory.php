<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Notifications\EmailTemplates;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Models\EmailTemplate;

class TemplateDirectory implements EmailTemplates
{
    public function options(array $types = []): array
    {
        return array_values(EmailTemplate::query()
            ->where('audience', TemplateAudience::Client)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->orderBy('name')->orderBy('id')
            ->get(['key', 'name'])
            ->map(fn (EmailTemplate $template): array => ['value' => $template->key, 'label' => $template->name])
            ->all());
    }
}
