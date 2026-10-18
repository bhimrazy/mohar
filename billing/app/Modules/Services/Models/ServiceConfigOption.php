<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One configurable-option selection of a service (option id → value id or quantity), normalized
 * so Catalog usage checks are plain indexed lookups on every database engine.
 *
 * @property int $id
 * @property int $service_id
 * @property int $option_id
 * @property int $value_id
 */
#[WithoutTimestamps]
#[Fillable(['service_id', 'option_id', 'value_id'])]
class ServiceConfigOption extends Model {}
