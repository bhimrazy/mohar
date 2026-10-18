<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $field sender|subject|body
 * @property string $match contains|equals
 * @property string $value
 */
#[Table('support_spam_rules')]
#[Fillable(['field', 'match', 'value'])]
class SpamRule extends Model
{
    public const FIELDS = ['sender', 'subject', 'body'];

    public const MATCHES = ['contains', 'equals'];

    public function matches(string $sender, string $subject, string $body): bool
    {
        $haystack = mb_strtolower(match ($this->field) {
            'sender' => $sender,
            'subject' => $subject,
            default => $body,
        });
        $needle = mb_strtolower(trim($this->value));

        if ($needle === '') {
            return false;
        }

        return $this->match === 'equals' ? trim($haystack) === $needle : str_contains($haystack, $needle);
    }
}
