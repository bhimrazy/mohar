<?php

namespace App\Modules\Kyc\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether one staff member has one client's identity documents open, and until when. The generation grows on every
 * Show, Hide and noticed end, so a request built from an older page can be told apart from a current one.
 *
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property int $generation
 * @property CarbonImmutable|null $expires_at
 */
#[Fillable(['user_id', 'client_id', 'generation', 'expires_at'])]
class KycReviewUnlock extends Model
{
    protected function casts(): array
    {
        return ['generation' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
