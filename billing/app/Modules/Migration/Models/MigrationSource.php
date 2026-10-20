<?php

namespace App\Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A named origin of imported records ("Old billing spreadsheet"). Its id map is its own: the same external id in two
 * sources is two records.
 *
 * @property int $id
 * @property string $driver csv (whmcs_api later)
 * @property string $name
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['driver', 'name', 'created_by'])]
class MigrationSource extends Model {}
