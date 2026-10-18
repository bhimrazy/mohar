<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Enums\InboundEmailStatus;
use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Models\SpamRule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inbound email log + spam rules (spec §9).
 */
class InboundEmailController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('support.inbound.manage');

        $emails = InboundEmail::query()->with('ticket:id,number')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('from', '%'.$request->string('search')->toString().'%')
                ->orWhereLike('subject', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('admin/support/inbound/index', [
            'emails' => $emails->through(fn (InboundEmail $email): array => [
                'id' => $email->id,
                'from' => $email->from,
                'to' => $email->to,
                'subject' => $email->subject,
                'status' => $email->status->value,
                'reason' => $email->reason,
                'ticket_id' => $email->ticket_id,
                'ticket_number' => $email->ticket?->number,
                'received_at' => $email->received_at->toIso8601String(),
            ]),
            'rules' => SpamRule::query()->orderBy('field')->orderBy('id')->get(['id', 'field', 'match', 'value']),
            'statuses' => array_map(fn (InboundEmailStatus $status): array => ['value' => $status->value, 'label' => str_replace('_', ' ', ucfirst($status->value))], InboundEmailStatus::cases()),
            'filters' => $request->only(['status', 'search']),
            'endpoint' => route('support.inbound.email', 'generic'),
        ]);
    }
}
