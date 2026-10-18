<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Services\AttachmentResponder;
use App\Modules\Support\Services\TicketAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    public function __invoke(Request $request, TicketAttachment $attachment, TicketAccess $access, AttachmentResponder $responder): StreamedResponse
    {
        $this->authorize('support.tickets.view');
        /** @var User $user */
        $user = $request->user();
        abort_unless($access->canAccess($user, $attachment->ticket), 403);

        return $responder->respond($attachment, $request->boolean('inline'));
    }
}
