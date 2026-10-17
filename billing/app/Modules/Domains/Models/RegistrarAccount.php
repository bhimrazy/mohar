<?php

namespace App\Modules\Domains\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuration of one registrar driver (credentials are stored encrypted).
 *
 * @property int $id
 * @property string $key
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property bool $test_mode
 */
#[Fillable(['key', 'is_active', 'settings', 'test_mode'])]
#[Hidden(['settings'])]
class RegistrarAccount extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'encrypted:array',
            'test_mode' => 'boolean',
        ];
    }
}
