<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $signature
 */
#[Table('support_staff_signatures')]
#[Fillable(['user_id', 'signature'])]
class StaffSignature extends Model {}
