<?php

namespace Tests\Support\Reports;

use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Models\User;

/** Database-backed: users ordered by name, then id so equal names page stably. */
class UserListReport implements Report
{
    public function key(): string
    {
        return 'user_list';
    }

    public function label(): string
    {
        return 'Users';
    }

    public function category(): string
    {
        return 'clients';
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(ReportQuery $query): array
    {
        return [new ReportColumn('name', 'Name', ColumnType::Text)];
    }

    public function rows(ReportQuery $query): iterable
    {
        $users = User::query()->orderBy('name')->orderBy('id');

        if ($query->limit !== null) {
            $users->offset($query->offset)->limit($query->limit);
        }

        foreach ($users->get() as $user) {
            yield ['name' => $user->name];
        }
    }

    public function count(ReportQuery $query): int
    {
        return User::query()->count();
    }
}
