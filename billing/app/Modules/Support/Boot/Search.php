<?php

namespace App\Modules\Support\Boot;

use App\Models\User;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;
use App\Support\Search\GlobalSearch;
use Illuminate\Contracts\Container\Container;

final class Search
{
    public function __invoke(Container $app): void
    {
        $search = $app->make(GlobalSearch::class);

        $search->provider('Tickets', function (string $term): array {
            $user = auth()->user();

            if (! $user instanceof User) {
                return [];
            }

            return array_values(app(TicketAccess::class)->scope(Ticket::query(), $user)
                ->where(fn ($query) => $query->whereLike('number', '%'.$term.'%')->orWhereLike('subject', '%'.$term.'%'))
                ->orderByDesc('id')->limit(5)->get()
                ->map(fn (Ticket $ticket): array => [
                    'title' => '#'.$ticket->number.' '.$ticket->subject,
                    'subtitle' => $ticket->requester_name.' · '.$ticket->status->label(),
                    'href' => route('admin.support.tickets.show', $ticket),
                ])->all());
        }, 'support.tickets.view');

        $search->provider('Knowledge base', fn (string $term): array => array_values(KbArticle::query()
            ->whereLike('title', '%'.$term.'%')->orderBy('title')->orderBy('id')->limit(5)->get()
            ->map(fn (KbArticle $article): array => [
                'title' => $article->title,
                'subtitle' => $article->is_published ? 'Published' : 'Draft',
                'href' => route('admin.support.kb.articles.edit', $article),
            ])->all()), 'support.kb.manage');
    }
}
