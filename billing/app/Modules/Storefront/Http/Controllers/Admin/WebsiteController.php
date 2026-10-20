<?php

namespace App\Modules\Storefront\Http\Controllers\Admin;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Currencies\CurrencyDirectory;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Storefront\Exceptions\DraftChanged;
use App\Modules\Storefront\Http\Requests\WebsiteContentRequest;
use App\Modules\Storefront\Services\ContentStore;
use App\Modules\Storefront\Services\HomePage;
use App\Modules\Storefront\Services\PriceDisplay;
use App\Modules\Storefront\Support\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Website: the home page and public layout content, edited as one draft, previewed at a signed URL and
 * published after a confirmation that lists the changes (spec §6.1).
 */
class WebsiteController extends Controller
{
    public function __construct(private ContentStore $store) {}

    public function edit(Request $request, PriceBook $priceBook, CurrencyDirectory $currencies, PriceDisplay $prices): Response
    {
        $this->authorize('storefront.content.manage');

        /** @var User $user */
        $user = $request->user();
        $draft = $this->store->draft();
        $published = $this->store->published();
        $live = $this->store->publishedRevision();
        $content = $draft !== null ? SiteContent::normalise($draft->content) : $published;
        $codes = array_map(fn ($display): string => $display->code, $currencies->displays());
        $withoutNotes = ['prices' => ['tax_inclusive' => [], 'notes' => []]] + SiteContent::defaults();
        $allInclusive = ['prices' => ['tax_inclusive' => $codes, 'notes' => []]] + SiteContent::defaults();

        return Inertia::render('admin/storefront/website', [
            'content' => $content,
            'draft' => $draft === null ? null : [
                'saved_at' => $draft->updated_at?->toIso8601String(),
                'saved_by' => $draft->author?->name,
                'changes' => SiteContent::changes($published, $draft->content),
                'preview_url' => URL::temporarySignedRoute('admin.storefront.website.preview', now()->addMinutes((int) config('storefront.preview_minutes', 60))),
                'review_token' => $this->store->reviewToken($draft),
            ],
            'published_at' => $live?->published_at?->toIso8601String(),
            'needs_text' => trim((string) $published['hero']['headline']) === '',
            'placeholder_headline' => HomePage::headline(SiteContent::defaults()),
            'can_publish' => $user->can('storefront.publish'),
            'home_url' => route('home'),
            'options' => [
                'groups' => array_map(fn (array $group): array => ['id' => $group['id'], 'name' => $group['name']], $priceBook->storefront($codes[0] ?? 'USD')),
                'icons' => SiteContent::ICONS,
                'sections' => SiteContent::SECTIONS,
                'currencies' => array_map(fn (string $code): array => [
                    'code' => $code,
                    // Only a currency priced for one market (storefront.tax_markets) can show prices with tax.
                    'market' => config("storefront.tax_markets.{$code}"),
                    'exclusive_note' => $prices->forCurrency($code, $withoutNotes)['note'],
                    'inclusive_note' => $prices->forCurrency($code, $allInclusive)['note'],
                ], $codes),
                'tax_rules_url' => Route::has('admin.billing.tax-rules.index') ? route('admin.billing.tax-rules.index') : null,
                'company_url' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', ['section' => 'company']) : null,
            ],
        ]);
    }

    public function update(WebsiteContentRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->store->saveDraft($request->content(), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved. Visitors still see the published version.')]);

        return to_route('admin.storefront.website.edit');
    }

    public function publish(Request $request): RedirectResponse
    {
        $this->authorize('storefront.publish');

        $reviewed = (string) $request->validate(['reviewed' => ['required', 'string', 'size:64']])['reviewed'];

        /** @var User $user */
        $user = $request->user();

        try {
            $published = $this->store->publish($user, $reviewed);
        } catch (DraftChanged $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('admin.storefront.website.edit');
        }

        Inertia::flash('toast', $published === null
            ? ['type' => 'error', 'message' => __('There is no draft to publish. Save your changes first.')]
            : ['type' => 'success', 'message' => __('Published. Visitors now see the new version.')]);

        return to_route('admin.storefront.website.edit');
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->authorize('storefront.content.manage');

        /** @var User $user */
        $user = $request->user();
        $this->store->discardDraft($user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft discarded.')]);

        return to_route('admin.storefront.website.edit');
    }
}
