<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Models\Department;
use App\Modules\Support\Settings\SupportSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which departments a client or guest may open tickets in (spec §2, §4.1): hidden departments only
 * by direct link (?department=slug); clients-only departments never for guests unless enabled.
 */
class CustomerDepartments
{
    public function __construct(private SupportSettings $settings) {}

    /**
     * @return Collection<int, Department>
     */
    public function available(bool $guest, ?string $directSlug = null): Collection
    {
        return Department::query()->ordered()->orderBy('id')->get()
            ->filter(fn (Department $department): bool => $this->allows($department, $guest, $directSlug))
            ->values();
    }

    public function allows(Department $department, bool $guest, ?string $directSlug = null): bool
    {
        if ($guest && $department->clients_only && ! $this->settings->bool('show_clients_only_to_guests')) {
            return false;
        }

        return ! $department->is_hidden || ($directSlug !== null && $directSlug === $department->slug);
    }

    /**
     * Opening is allowed in hidden departments (direct link) but not in clients-only ones for guests.
     */
    public function canOpenIn(Department $department, bool $guest): bool
    {
        return ! ($guest && $department->clients_only && ! $this->settings->bool('show_clients_only_to_guests'));
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return list<array{value: string, label: string, description: string|null}>
     */
    public function options(Collection $departments): array
    {
        return array_values($departments->map(fn (Department $department): array => [
            'value' => (string) $department->id,
            'label' => $department->name,
            'description' => $department->description,
        ])->all());
    }
}
