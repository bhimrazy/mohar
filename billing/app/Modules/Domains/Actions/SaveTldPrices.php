<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use Illuminate\Support\Facades\DB;

/**
 * Save one pricing grid (TLD × currency × client-group slab): one row per term; a term with no prices
 * is removed. Rows are matched explicitly because a NULL client_group_id never conflicts in a unique index.
 */
class SaveTldPrices
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  list<array{years: int, register: int|null, transfer: int|null, renew: int|null}>  $rows
     * @param  bool|null  $manual  "set manually — don't auto-convert" for the whole grid; null keeps each row's flag
     */
    public function handle(Tld $tld, string $currency, ?int $clientGroupId, array $rows, ?bool $manual = null): void
    {
        $currency = strtoupper($currency);

        DB::transaction(function () use ($tld, $currency, $clientGroupId, $rows, $manual): void {
            $existing = TldPrice::query()
                ->where('tld_id', $tld->id)
                ->where('currency', $currency)
                ->when($clientGroupId === null, fn ($query) => $query->whereNull('client_group_id'), fn ($query) => $query->where('client_group_id', $clientGroupId))
                ->lockForUpdate()
                ->get()
                ->keyBy('years');

            foreach ($rows as $row) {
                $price = $existing->get($row['years']);
                $amounts = ['register' => $row['register'], 'transfer' => $row['transfer'], 'renew' => $row['renew'], 'is_manual' => $manual ?? ($price !== null && $price->is_manual), 'enabled' => true];

                if ($amounts['register'] === null && $amounts['transfer'] === null && $amounts['renew'] === null) {
                    $price?->delete();

                    continue;
                }

                if ($price !== null) {
                    $price->update($amounts);
                } else {
                    TldPrice::query()->create(['tld_id' => $tld->id, 'currency' => $currency, 'client_group_id' => $clientGroupId, 'years' => $row['years'], ...$amounts]);
                }
            }
        });

        $this->activity->log("TLD {$tld->extension} {$currency} prices updated", $tld, null, ['client_group_id' => $clientGroupId, 'manual' => $manual]);
    }
}
