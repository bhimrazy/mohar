<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Where `security:reencrypt` stopped in one target (Core\Security\Reencryptor).
 *
 * @property int $id
 * @property string $target
 * @property string $key_fingerprint
 * @property string|null $last_key
 * @property bool $completed
 * @property array<string, int> $counts
 */
#[Fillable(['target', 'key_fingerprint', 'last_key', 'completed', 'counts'])]
class ReencryptionCheckpoint extends Model
{
    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'counts' => 'array',
        ];
    }
}
