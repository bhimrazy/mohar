<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Enums\PriceRounding;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Actions\Currencies\SaveCurrency;
use App\Modules\Platform\Http\Requests\CurrencyRequest;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Services\ExchangeRateStatus;
use App\Support\Currencies\ExchangeRateSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function __construct(private ExchangeRateSources $sources, private ExchangeRateStatus $status) {}

    public function index(Request $request): Response
    {
        $this->authorize('core.currencies.manage');

        $clientCounts = Client::query()->selectRaw('currency, count(*) as aggregate')->groupBy('currency')->pluck('aggregate', 'currency')->map(fn (mixed $count): int => (int) $count);

        return Inertia::render('admin/platform/currencies/index', [
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->orderBy('id')->get()->map(fn (Currency $currency): array => [
                ...$this->present($currency),
                'clients_count' => (int) ($clientCounts[$currency->code] ?? 0),
            ]),
            'reviews' => ExchangeRateReview::query()->pending()->orderBy('currency')->orderBy('id')->get()
                ->map(fn (ExchangeRateReview $review): array => $this->presentReview($review))->values(),
            'staleAfterDays' => $this->status->staleAfterDays(),
            'canManageRates' => $request->user()?->hasPermission('core.currencies.rates') ?? false,
            'hasAutoRates' => Currency::query()->where('is_default', false)->where('rate_source', '!=', ExchangeRateSources::MANUAL)->exists(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('core.currencies.manage');

        return Inertia::render('admin/platform/currencies/form', $this->options());
    }

    public function store(CurrencyRequest $request, SaveCurrency $save): RedirectResponse
    {
        $currency = $save->handle(null, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Currency :code created.', ['code' => $currency->code])]);

        return to_route('admin.platform.currencies.edit', $currency);
    }

    public function edit(Request $request, Currency $currency): Response
    {
        $this->authorize('core.currencies.manage');

        $review = ExchangeRateReview::query()->pending()->where('currency', $currency->code)->latest('id')->first();

        return Inertia::render('admin/platform/currencies/form', [
            'currency' => $this->present($currency),
            ...$this->options(),
            'sources' => array_values(array_map(fn (ExchangeRateSource $source): array => [
                'key' => $source->key(),
                'label' => $source->label(),
                'description' => $source->description(),
            ], $this->sources->for($currency->code))),
            'roundings' => array_map(fn (PriceRounding $rounding): array => ['value' => $rounding->value, 'label' => $rounding->label()], PriceRounding::cases()),
            'history' => $currency->is_default ? [] : $this->status->dailySeries($currency),
            'review' => $review ? $this->presentReview($review) : null,
            'canManageRates' => $request->user()?->hasPermission('core.currencies.rates') ?? false,
        ]);
    }

    public function update(CurrencyRequest $request, Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $save->handle($currency, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Currency :code saved.', ['code' => $currency->code])]);

        return to_route('admin.platform.currencies.edit', $currency);
    }

    public function makeDefault(Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $this->authorize('core.currencies.manage');
        $this->authorize('core.currencies.rates');

        $save->makeDefault($currency);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code is now the default currency.', ['code' => $currency->code])]);

        return back();
    }

    public function destroy(Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $this->authorize('core.currencies.manage');

        $save->delete($currency);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Currency :code deleted.', ['code' => $currency->code])]);

        return to_route('admin.platform.currencies.index');
    }

    /**
     * @return array{code: string, name: string, prefix: ?string, suffix: ?string, decimals: int, format: string, exchange_rate: ?string}
     */
    private function payload(CurrencyRequest $request): array
    {
        return [
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'prefix' => $request->input('prefix'),
            'suffix' => $request->input('suffix'),
            'decimals' => $request->integer('decimals'),
            'format' => $request->string('format')->toString(),
            'exchange_rate' => $request->filled('exchange_rate') ? $request->string('exchange_rate')->toString() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Currency $currency): array
    {
        $source = $this->sources->get($currency->rate_source);

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'prefix' => $currency->prefix,
            'suffix' => $currency->suffix,
            'decimals' => $currency->decimals,
            'format' => $currency->format,
            'exchange_rate' => $currency->exchange_rate,
            'is_default' => $currency->is_default,
            'rate_source' => $currency->rate_source,
            'rate_source_label' => $source?->label() ?? 'Manual',
            'rate_updated_at' => $currency->rate_updated_at?->toIso8601String(),
            'rate_checked_at' => $currency->rate_checked_at?->toIso8601String(),
            'is_stale' => $this->status->isStale($currency),
            'price_rounding' => $currency->price_rounding->value,
            'price_buffer_percent' => $currency->price_buffer_percent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReview(ExchangeRateReview $review): array
    {
        return [
            'id' => $review->id,
            'currency' => $review->currency,
            'current_rate' => $review->current_rate,
            'proposed_rate' => $review->proposed_rate,
            'change_percent' => $review->change_percent,
            'source' => $this->sources->get($review->source)?->label() ?? $review->source,
            'effective_at' => $review->effective_at->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{formats: list<string>, decimals: list<int>, defaultCode: string}
     */
    private function options(): array
    {
        return ['formats' => Currency::FORMATS, 'decimals' => Currency::DECIMALS, 'defaultCode' => Currency::default()->code];
    }
}
