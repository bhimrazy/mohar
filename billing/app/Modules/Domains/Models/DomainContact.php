<?php

namespace App\Modules\Domains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local cache of the registrar contacts of a domain.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $role
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company
 * @property string $email
 * @property string|null $phone
 * @property string|null $address_1
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property CarbonImmutable|null $synced_at
 */
#[Fillable(['domain_id', 'role', 'first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'synced_at'])]
class DomainContact extends Model
{
    public const ROLES = ['registrant', 'admin', 'tech', 'billing'];

    public const FIELDS = ['first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
