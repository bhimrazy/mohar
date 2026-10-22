<?php

namespace App\Modules\Domains\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of registrar_refs (registrars.md C4); read through RegistrarRefs, never by drivers.
 *
 * @property int $id
 * @property string $registrar_key
 * @property string $ref_type
 * @property string $local_type
 * @property int $local_id
 * @property string $remote_id
 * @property string $payload_hash
 * @property string|null $secret encrypted
 */
#[Table('registrar_refs')]
#[Fillable(['registrar_key', 'ref_type', 'local_type', 'local_id', 'remote_id', 'payload_hash', 'secret'])]
#[Hidden(['secret'])]
class RegistrarRefRecord extends Model
{
    protected function casts(): array
    {
        return [
            'local_id' => 'integer',
            'secret' => 'encrypted',
        ];
    }
}
