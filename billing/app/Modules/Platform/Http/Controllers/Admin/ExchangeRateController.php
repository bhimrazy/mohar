<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Actions\Currencies\ReviewExchangeRate;
use App\Modules\Platform\Actions\Currencies\SaveCurrency;
use App\Modules\Platform\Actions\Currencies\UpdateExchangeRates;
use App\Modules\Platform\Http\Requests\CurrencyRatesRequest;
use App\Modules\Platform\Models\ExchangeRateReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Exchange-rate changes: per-currency source and rules, "Update rates now", and held-change reviews.
 * Every route here is behind the sensitive core.currencies.rates permission and a recent password.
 */
class ExchangeRateController extends Controller
{
    public function update(CurrencyRatesRequest $request, Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $changed = $save->updateRates($currency, $request->payload());
        Inertia::flash('toast', ['type' => 'success', 'message' => $changed
            ? __('Exchange rate for :code updated.', ['code' => $currency->code])
            : __('Rate settings for :code saved.', ['code' => $currency->code])]);

        return to_route('admin.platform.currencies.edit', $currency);
    }

    public function refresh(Request $request, UpdateExchangeRates $update): RedirectResponse
    {
        $this->authorize('core.currencies.rates');

        $result = $update->handle($this->staff($request));
        $parts = [];

        foreach (['Updated: :codes.' => $result['updated'], 'Unchanged: :codes.' => $result['unchanged'], 'Held for review: :codes.' => $result['held'], 'Failed: :codes.' => array_keys($result['failed'])] as $sentence => $codes) {
            if ($codes !== []) {
                $parts[] = $this->sentence($sentence, implode(', ', $codes));
            }
        }

        Inertia::flash('toast', [
            'type' => $result['failed'] !== [] ? 'error' : ($result['held'] !== [] ? 'warning' : 'success'),
            'message' => $parts === [] ? __('No currency uses an automatic rate source.') : implode(' ', $parts),
        ]);

        return back();
    }

    public function approve(Request $request, ExchangeRateReview $review, ReviewExchangeRate $reviews): RedirectResponse
    {
        $this->authorize('core.currencies.rates');

        $reviews->approve($review, $this->staff($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New :code rate approved.', ['code' => $review->currency])]);

        return back();
    }

    public function reject(Request $request, ExchangeRateReview $review, ReviewExchangeRate $reviews): RedirectResponse
    {
        $this->authorize('core.currencies.rates');

        $reviews->reject($review, $this->staff($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The :code rate change was rejected; the current rate stays.', ['code' => $review->currency])]);

        return back();
    }

    /**
     * A translated fixed sentence (a translation file may only map these keys to strings).
     */
    private function sentence(string $key, string $codes): string
    {
        $line = __($key, ['codes' => $codes]);

        return is_string($line) ? $line : str_replace(':codes', $codes, $key);
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
