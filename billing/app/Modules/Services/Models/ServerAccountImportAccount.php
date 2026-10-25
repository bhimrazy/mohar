<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One account of a fetched list (server_account_import_accounts). Read and written through AccountList, which filters
 * on the plain columns and opens only the `payload` of the accounts it needs: the whole account row, with the contact
 * email, encrypted.
 *
 * @property int $id
 * @property int $server_account_import_id
 * @property int $position
 * @property string $username
 * @property string|null $domain lower case
 * @property string|null $email_hash
 * @property string $category
 * @property int|null $client_id
 * @property array<string, mixed> $payload
 */
#[Hidden(['payload'])]
class ServerAccountImportAccount extends Model
{
    public $timestamps = false;

    protected $table = 'server_account_import_accounts';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
