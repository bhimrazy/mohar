<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Staff edit of service fields. Changing next_due_date re-anchors the billing day and keeps
 * next_invoice_date ≥ next_due_date.
 */
class UpdateService
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  validated ServiceRequest data
     */
    public function handle(Service $service, array $data): Service
    {
        return DB::transaction(function () use ($service, $data): Service {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);

            if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
                unset($data['password']);
            }

            $locked->fill($data);

            if ($locked->isDirty('next_due_date') && $locked->next_due_date !== null) {
                $locked->billing_anchor_day = $locked->next_due_date->day;
            }

            if ($locked->next_due_date !== null && ($locked->next_invoice_date === null || $locked->next_invoice_date->lt($locked->next_due_date))) {
                $locked->next_invoice_date = $locked->next_due_date;
            }

            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                $this->activity->log('Service updated', $locked, $locked->client_id, ['changed' => array_values(array_diff($changed, ['password']))]);
            }

            return $locked;
        });
    }
}
