<?php

namespace App\Modules\Domains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An identity or company document for a .np request. The file on the objects disk holds the encrypted bytes;
 * it is deleted after delete_after (set on approval or rejection) or when the client replaces it.
 *
 * @property int $id
 * @property int $np_registration_id
 * @property string $kind identity|registration_certificate|request_letter
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size bytes, before encryption
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $delete_after
 * @property CarbonImmutable|null $created_at
 * @property-read NpRegistration $registration
 */
#[Fillable(['np_registration_id', 'kind', 'path', 'original_name', 'mime', 'size', 'uploaded_by', 'delete_after'])]
class NpDocument extends Model
{
    public const KINDS = ['identity', 'registration_certificate', 'request_letter'];

    public const DISK = 'objects';

    protected function casts(): array
    {
        return ['size' => 'integer', 'delete_after' => 'date'];
    }

    /**
     * @return BelongsTo<NpRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(NpRegistration::class, 'np_registration_id');
    }
}
