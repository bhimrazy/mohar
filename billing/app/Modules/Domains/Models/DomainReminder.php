<?php

namespace App\Modules\Domains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ICANN-style expiry reminder log; never pruned. One row per domain, expiry cycle and offset, written when the
 * reminder is dispatched to Notifications (delivery confirmation is Notifications' log).
 *
 * @property int $id
 * @property int $domain_id
 * @property int $client_id
 * @property CarbonImmutable $expiry_date
 * @property int $offset_days
 * @property string $template_key
 * @property list<string> $recipients
 * @property CarbonImmutable $dispatched_at
 */
#[Fillable(['domain_id', 'client_id', 'expiry_date', 'offset_days', 'template_key', 'recipients', 'dispatched_at'])]
class DomainReminder extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'offset_days' => 'integer',
            'recipients' => 'array',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
