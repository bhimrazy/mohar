<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $value decrypted on read
 */
#[Fillable(['name', 'value'])]
#[Hidden(['value'])]
class InstallSecret extends Model
{
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
