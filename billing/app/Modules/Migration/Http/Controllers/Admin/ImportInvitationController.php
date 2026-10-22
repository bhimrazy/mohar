<?php

namespace App\Modules\Migration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ImportInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Import report → Send invitations (client-import spec §5.7): how many imported owner logins have no password, by
 * invitation state, with filters, and the throttled send.
 */
class ImportInvitationController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, MigrationRun $run, ImportInvitations $invitations, Settings $settings): Response
    {
        $this->authorize('migration.runs.view');

        $filters = $this->filters($request);
        $perHour = max(1, (int) $settings->get('migration.invite_per_hour'));
        $logins = $invitations->candidates($run, $filters)->orderBy('u.id')->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (stdClass $row): array => [
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'client' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')).($row->company_name ? ' ('.$row->company_name.')' : ''),
                'client_href' => route('admin.clients.show', (int) $row->client_id),
                'state' => (string) $row->state,
                'not_sent' => $row->state === 'not_invited' ? $row->not_sent_reason : null,
                'invited_at' => $row->invited_at === null ? null : CarbonImmutable::parse((string) $row->invited_at)->toIso8601String(),
                'expires_at' => $row->expires_at === null ? null : CarbonImmutable::parse((string) $row->expires_at)->toIso8601String(),
            ]);
        $counts = $invitations->counts($run, $filters);
        $canSend = (bool) $request->user()?->hasPermission('migration.runs.execute');

        return Inertia::render('admin/migration/invitations', [
            'run' => ['id' => $run->id, 'name' => $run->label(), 'status' => $run->status, 'uploaded_at' => $run->created_at?->toIso8601String()],
            'counts' => $counts,
            'logins' => $logins,
            'filters' => $filters,
            'groups' => ClientGroup::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn (ClientGroup $group): array => ['value' => (string) $group->id, 'label' => $group->name]),
            'perHour' => $perHour,
            'sending' => MigrationInvitation::query()->where('migration_run_id', $run->id)->where(fn ($query) => $query->where('status', MigrationInvitation::QUEUED)
                ->orWhere(fn ($query) => $query->where('status', MigrationInvitation::SENDING)->where('updated_at', '>=', now()->subSeconds(MigrationInvitation::STALE_SECONDS))))->count(),
            'blocked' => $canSend ? $invitations->blocked($run) : __('You need permission to run client imports to send invitations.'),
        ]);
    }

    public function store(Request $request, MigrationRun $run, ImportInvitations $invitations, Settings $settings): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $filters = $this->filters($request);
        /** @var User $user */
        $user = $request->user();

        $counts = $invitations->counts($run, $filters);

        // Nothing new to queue but rows still waiting: start the sender again (it may have stopped).
        if ($run->status === MigrationRun::COMPLETED && $counts['not_invited'] + $counts['expired'] === 0
            && MigrationInvitation::query()->where('migration_run_id', $run->id)->where('status', MigrationInvitation::QUEUED)->exists()) {
            SendInvitations::next();
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Sending the invitations that are waiting.')]);

            return back();
        }

        $queued = $invitations->queue($run, $filters, $user);
        $perHour = max(1, (int) $settings->get('migration.invite_per_hour'));
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} 1 invitation is queued.|[2,*] :count invitations are queued, at most :rate an hour.', $queued, ['count' => number_format($queued), 'rate' => number_format($perHour)])]);

        return back();
    }

    /**
     * @return array{scope: 'created'|'all', group: string|null, status: 'active'|'inactive'|null}
     */
    private function filters(Request $request): array
    {
        $data = validator($request->only(['scope', 'group', 'status']), [
            'scope' => ['nullable', Rule::in(['created', 'all'])],
            'group' => ['nullable', 'string', 'max:20', Rule::in(['none', ...ClientGroup::query()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ])->validate();

        return ['scope' => ($data['scope'] ?? 'created') === 'all' ? 'all' : 'created', 'group' => $data['group'] ?? null, 'status' => $data['status'] ?? null];
    }
}
