<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Support\Actions\SaveDepartment;
use App\Modules\Support\Http\Requests\DepartmentRequest;
use App\Modules\Support\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(): Response
    {
        $this->authorize('support.departments.manage');

        return Inertia::render('admin/support/departments/index', [
            'departments' => Department::query()->ordered()->orderBy('id')->withCount(['tickets', 'staff'])->get()
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'email' => $department->email,
                    'is_hidden' => $department->is_hidden,
                    'clients_only' => $department->clients_only,
                    'replies_only_by_email' => $department->replies_only_by_email,
                    'tickets_count' => (int) $department->getAttribute('tickets_count'),
                    'staff_count' => (int) $department->getAttribute('staff_count'),
                ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('support.departments.manage');

        return Inertia::render('admin/support/departments/form', ['staff' => $this->staffOptions()]);
    }

    public function store(DepartmentRequest $request, SaveDepartment $save): RedirectResponse
    {
        $department = $save->handle(null, $request->attributesForModel(), $request->staffIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department :name created.', ['name' => $department->name])]);

        return to_route('admin.support.departments.index');
    }

    public function edit(Department $department): Response
    {
        $this->authorize('support.departments.manage');

        return Inertia::render('admin/support/departments/form', [
            'department' => [
                ...$department->only(['id', 'name', 'slug', 'description', 'email', 'is_hidden', 'clients_only', 'replies_only_by_email', 'send_autoresponse', 'autoresponse_message', 'prevent_client_close', 'sort_order']),
                'staff' => $department->staff()->pluck('users.id')->map(fn (int $id): string => (string) $id)->all(),
            ],
            'staff' => $this->staffOptions(),
        ]);
    }

    public function update(DepartmentRequest $request, Department $department, SaveDepartment $save): RedirectResponse
    {
        $save->handle($department, $request->attributesForModel(), $request->staffIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department :name updated.', ['name' => $department->name])]);

        return to_route('admin.support.departments.index');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('support.departments.manage');

        if ($department->tickets()->exists()) {
            throw ValidationException::withMessages(['department' => __('Move this department\'s tickets to another department before deleting it.')]);
        }

        $department->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department :name deleted.', ['name' => $department->name])]);

        return to_route('admin.support.departments.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function staffOptions(): array
    {
        return array_values(User::query()->where('is_staff', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => $user->name])->all());
    }
}
