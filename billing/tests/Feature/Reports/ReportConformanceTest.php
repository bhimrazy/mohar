<?php

use App\Data\Reports\ReportQuery;
use App\Models\User;
use App\Support\Reports\ReportConformance;
use App\Support\Reports\ReportConformanceFailure;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Tests\Support\Reports\UserListReport;

/** The shipped conformance kit (ADR 0020, Amendment (#515)): it passes a sound report and fails the broken ones. */
class PerRowQueryReport extends UserListReport
{
    public function key(): string
    {
        return 'per_row';
    }

    public function rows(ReportQuery $query): iterable
    {
        foreach (parent::rows($query) as $row) {
            User::query()->where('name', $row['name'])->count();

            yield $row;
        }
    }
}

class IgnoresPagingReport extends UserListReport
{
    public function key(): string
    {
        return 'no_paging';
    }

    public function rows(ReportQuery $query): iterable
    {
        return User::query()->orderBy('name')->orderBy('id')->get()->map(fn (User $user): array => ['name' => $user->name]);
    }
}

function conformanceQuery(string $key): ReportQuery
{
    actingAsStaff(["reports.{$key}.view"]);
    User::factory()->count(5)->create();

    return app(ReportExporter::class)->query($key, [], auth()->id());
}

test('a sound report passes both checks', function () {
    app(ReportRegistry::class)->register('user_list', UserListReport::class);
    $query = conformanceQuery('user_list');

    ReportConformance::assertPages('user_list', $query, 2);
    ReportConformance::assertQueryBudget('user_list', $query);

    expect(true)->toBeTrue(); // the kit throws instead of asserting
});

test('a report that ignores the page fails the paging check', function () {
    app(ReportRegistry::class)->register('no_paging', IgnoresPagingReport::class);

    expect(fn () => ReportConformance::assertPages('no_paging', conformanceQuery('no_paging'), 2))->toThrow(ReportConformanceFailure::class);
});

test('a report that queries per row fails the budget check', function () {
    app(ReportRegistry::class)->register('per_row', PerRowQueryReport::class);

    expect(fn () => ReportConformance::assertQueryBudget('per_row', conformanceQuery('per_row')))
        ->toThrow(ReportConformanceFailure::class, 'queries per row');
});

test('a page over the budget fails with the count', function () {
    app(ReportRegistry::class)->register('user_list', UserListReport::class);

    expect(fn () => ReportConformance::assertQueryBudget('user_list', conformanceQuery('user_list'), 1))
        ->toThrow(ReportConformanceFailure::class, 'the budget is 1');
});
