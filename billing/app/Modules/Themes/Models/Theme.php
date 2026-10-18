<?php

namespace App\Modules\Themes\Models;

use App\Modules\Themes\Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom theme saved from the admin theme editor. `key` is immutable (settings and user
 * preferences reference it); `version` increases on every save so cached CSS is refreshed.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $base_preset
 * @property array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>} $tokens
 * @property int $version
 * @property int|null $created_by
 */
#[UseFactory(ThemeFactory::class)]
#[Fillable(['key', 'name', 'base_preset', 'tokens', 'version', 'created_by'])]
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['tokens' => 'array', 'version' => 'integer'];
    }
}
