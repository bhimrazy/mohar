<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Actions\Currencies\SaveCurrency;
use App\Modules\Platform\Http\Requests\CurrencyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function index(): Response
    {
        $this->authorize('core.currencies.manage');

        $clientCounts = Client::query()->selectRaw('currency, count(*) as aggregate')->groupBy('currency')->pluck('aggregate', 'currency')->map(fn (mixed $count): int => (int) $count);

        return Inertia::render('admin/platform/currencies/index', [
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->orderBy('id')->get()->map(fn (Currency $currency): array => [
                ...$this->present($currency),
                'clients_count' => (int) ($clientCounts[$currency->code] ?? 0),
            ]),
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

        return to_route('admin.platform.currencies.index');
    }

    public function edit(Currency $currency): Response
    {
        $this->authorize('core.currencies.manage');

        return Inertia::render('admin/platform/currencies/form', ['currency' => $this->present($currency), ...$this->options()]);
    }

    public function update(CurrencyRequest $request, Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $save->handle($currency, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Currency :code saved.', ['code' => $currency->code])]);

        return to_route('admin.platform.currencies.index');
    }

    public function makeDefault(Currency $currency, SaveCurrency $save): RedirectResponse
    {
        $this->authorize('core.currencies.manage');

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
     * @return array{code: string, name: string, prefix: ?string, suffix: ?string, decimals: int, format: string, exchange_rate: string}
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
            'exchange_rate' => $request->string('exchange_rate')->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Currency $currency): array
    {
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
            'rate_updated_at' => $currency->rate_updated_at?->toIso8601String(),
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
