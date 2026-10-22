<?php

namespace App\Support\Security;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model whose encrypted casts (`encrypted`, `encrypted:*`, AsEncryptedArrayObject, AsEncryptedCollection)
 * hold values encrypted with APP_KEY. `security:reencrypt` reads the casts from the model when it runs, so the
 * columns are listed once, in the model.
 *
 * @api
 */
final readonly class EncryptedModel
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public string $model) {}
}
