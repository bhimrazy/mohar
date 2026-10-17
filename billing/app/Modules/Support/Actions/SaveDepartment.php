<?php

namespace App\Modules\Support\Actions;

use App\Modules\Support\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a department together with its assigned staff.
 */
class SaveDepartment
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $staffIds
     */
    public function handle(?Department $department, array $attributes, array $staffIds): Department
    {
        return DB::transaction(function () use ($department, $attributes, $staffIds): Department {
            $department ??= new Department;
            $department->fill($attributes)->save();
            $department->staff()->sync($staffIds);

            return $department;
        });
    }
}
