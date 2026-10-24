<?php

namespace App\Modules\Kyc\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Actions\DeleteOrphanFiles;
use App\Modules\Kyc\Models\KycOrphanFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Identity image files without a document (#298): the daily check lists them, staff with the review permission
 * delete them after a step-up. Paths never reach the browser.
 */
class OrphanFileController extends Controller
{
    public function index(DeleteOrphanFiles $delete): Response
    {
        $this->authorize(ReviewController::PERMISSION);
        ['files' => $files, 'digest' => $digest] = $delete->snapshot();
        $clients = Client::query()->whereIn('id', $files->pluck('client_id')->unique())->get()->keyBy('id');

        return Inertia::render('admin/kyc/orphans', [
            'files' => $files->map(fn (KycOrphanFile $file): array => [
                'id' => $file->id,
                'client' => ($client = $clients->get($file->client_id)) instanceof Client ? ['id' => $client->id, 'name' => $client->display_name] : null,
                'modified_at' => $file->modified_at?->toIso8601String(),
                'found_at' => $file->found_at->toIso8601String(),
                'last_seen_at' => $file->last_seen_at->toIso8601String(),
            ])->values(),
            'digest' => $digest,
        ]);
    }

    public function destroy(Request $request, DeleteOrphanFiles $delete): RedirectResponse
    {
        $this->authorize(ReviewController::PERMISSION);
        $data = $request->validate(['digest' => ['required', 'string', 'size:64']]);

        $deleted = $delete->handle($data['digest']);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No file needed deleting; the list is cleared.|{1} 1 file deleted.|[2,*] :count files deleted.', $deleted)]);

        return to_route('admin.kyc.orphans.index');
    }
}
