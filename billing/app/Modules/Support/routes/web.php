<?php

use App\Modules\Support\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Modules\Support\Http\Controllers\Admin\CannedReplyCategoryController;
use App\Modules\Support\Http\Controllers\Admin\CannedReplyController;
use App\Modules\Support\Http\Controllers\Admin\DepartmentController;
use App\Modules\Support\Http\Controllers\Admin\InboundEmailController;
use App\Modules\Support\Http\Controllers\Admin\KbArticleController;
use App\Modules\Support\Http\Controllers\Admin\KbCategoryController;
use App\Modules\Support\Http\Controllers\Admin\NetworkIncidentController;
use App\Modules\Support\Http\Controllers\Admin\SpamRuleController;
use App\Modules\Support\Http\Controllers\Admin\StaffSignatureController;
use App\Modules\Support\Http\Controllers\Admin\TicketAttachmentController as AdminAttachmentController;
use App\Modules\Support\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Modules\Support\Http\Controllers\Admin\TicketMessageController;
use App\Modules\Support\Http\Controllers\Client\TicketController as ClientTicketController;
use App\Modules\Support\Http\Controllers\Public\AnnouncementController;
use App\Modules\Support\Http\Controllers\Public\GuestTicketController;
use App\Modules\Support\Http\Controllers\Public\KnowledgebaseController;
use App\Modules\Support\Http\Controllers\Public\NetworkStatusController;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('support')->name('support.')->group(function (): void {
    Route::get('tickets', [AdminTicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [AdminTicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [AdminTicketController::class, 'store'])->name('tickets.store');
    Route::post('tickets/merge', [AdminTicketController::class, 'merge'])->name('tickets.merge');
    Route::post('tickets/bulk', [AdminTicketController::class, 'bulk'])->name('tickets.bulk');
    Route::get('tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::patch('tickets/{ticket}', [AdminTicketController::class, 'update'])->name('tickets.update');
    Route::delete('tickets/{ticket}', [AdminTicketController::class, 'destroy'])->name('tickets.destroy');
    Route::post('tickets/{ticket}/replies', [TicketMessageController::class, 'reply'])->name('tickets.replies.store');
    Route::post('tickets/{ticket}/notes', [TicketMessageController::class, 'note'])->name('tickets.notes.store');
    Route::patch('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'update'])->name('tickets.messages.update')->scopeBindings();
    Route::delete('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'destroy'])->name('tickets.messages.destroy')->scopeBindings();
    Route::post('tickets/{ticket}/inbound/{inboundEmail}/import', [TicketMessageController::class, 'import'])->name('tickets.inbound.import')->scopeBindings();
    Route::get('attachments/{attachment}', AdminAttachmentController::class)->name('attachments.show');
    Route::put('signature', StaffSignatureController::class)->name('signature.update');

    Route::resource('departments', DepartmentController::class)->except('show');
    Route::resource('canned-replies', CannedReplyController::class)->except('show')->parameters(['canned-replies' => 'cannedReply']);
    Route::post('canned-reply-categories', [CannedReplyCategoryController::class, 'store'])->name('canned-reply-categories.store');
    Route::delete('canned-reply-categories/{category}', [CannedReplyCategoryController::class, 'destroy'])->name('canned-reply-categories.destroy');

    Route::prefix('kb')->name('kb.')->group(function (): void {
        Route::resource('articles', KbArticleController::class)->except('show');
        Route::post('categories', [KbCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [KbCategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [KbCategoryController::class, 'destroy'])->name('categories.destroy');
    });

    Route::resource('announcements', AdminAnnouncementController::class)->except('show');

    Route::get('network', [NetworkIncidentController::class, 'index'])->name('network.index');
    Route::get('network/create', [NetworkIncidentController::class, 'create'])->name('network.create');
    Route::post('network', [NetworkIncidentController::class, 'store'])->name('network.store');
    Route::get('network/{incident}/edit', [NetworkIncidentController::class, 'edit'])->name('network.edit');
    Route::put('network/{incident}', [NetworkIncidentController::class, 'update'])->name('network.update');
    Route::delete('network/{incident}', [NetworkIncidentController::class, 'destroy'])->name('network.destroy');
    Route::post('network/{incident}/updates', [NetworkIncidentController::class, 'addUpdate'])->name('network.updates.store');
    Route::post('network/{incident}/resolve', [NetworkIncidentController::class, 'resolve'])->name('network.resolve');

    Route::get('inbound', [InboundEmailController::class, 'index'])->name('inbound.index');
    Route::post('spam-rules', [SpamRuleController::class, 'store'])->name('spam-rules.store');
    Route::delete('spam-rules/{spamRule}', [SpamRuleController::class, 'destroy'])->name('spam-rules.destroy');
}));

Route::clientArea(fn () => Route::prefix('support/tickets')->name('support.tickets.')->group(function (): void {
    Route::get('/', [ClientTicketController::class, 'index'])->name('index');
    Route::get('create', [ClientTicketController::class, 'create'])->name('create');
    Route::post('/', [ClientTicketController::class, 'store'])->name('store');
    Route::get('{number}', [ClientTicketController::class, 'show'])->name('show');
    Route::post('{number}/replies', [ClientTicketController::class, 'reply'])->name('reply');
    Route::post('{number}/close', [ClientTicketController::class, 'close'])->name('close');
    Route::post('{number}/messages/{message}/rating', [ClientTicketController::class, 'rate'])->name('rate');
    Route::get('{number}/attachments/{attachment}', [ClientTicketController::class, 'attachment'])->name('attachments.show');
}));

/*
| Public pages (no login). Guest ticket access is by the ticket's random access key (?key=).
*/
Route::middleware('throttle:support-public')->group(function (): void {
    Route::prefix('support/tickets')->name('support.tickets.')->group(function (): void {
        Route::get('create', [GuestTicketController::class, 'create'])->name('create');
        Route::post('/', [GuestTicketController::class, 'store'])->middleware('throttle:support-guest')->name('store');
        Route::get('submitted', [GuestTicketController::class, 'submitted'])->name('submitted');
        Route::get('{number}', [GuestTicketController::class, 'show'])->name('show');
        Route::post('{number}/replies', [GuestTicketController::class, 'reply'])->middleware('throttle:support-guest')->name('reply');
        Route::post('{number}/close', [GuestTicketController::class, 'close'])->name('close');
        Route::post('{number}/messages/{message}/rating', [GuestTicketController::class, 'rate'])->name('rate');
        Route::get('{number}/attachments/{attachment}', [GuestTicketController::class, 'attachment'])->name('attachments.show');
    });

    Route::prefix('knowledgebase')->name('support.kb.')->group(function (): void {
        Route::get('/', [KnowledgebaseController::class, 'index'])->name('index');
        Route::get('category/{slug}', [KnowledgebaseController::class, 'category'])->name('category');
        Route::get('{slug}', [KnowledgebaseController::class, 'article'])->name('article');
        Route::post('{slug}/vote', [KnowledgebaseController::class, 'vote'])->middleware('throttle:support-guest')->name('vote');
    });

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('support.announcements.index');
    Route::get('announcements/feed', [AnnouncementController::class, 'feed'])->name('support.announcements.feed');
    Route::get('announcements/{slug}', [AnnouncementController::class, 'show'])->name('support.announcements.show');
    Route::get('network-status', NetworkStatusController::class)->name('support.network.index');
});
