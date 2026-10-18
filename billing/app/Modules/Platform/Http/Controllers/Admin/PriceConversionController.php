<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Contracts\Currencies\PriceConverter;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Currencies\PriceConverters;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Converted prices: preview what regenerating other-currency prices from the default currency would change,
 * then apply it per module. Never touches existing services or domains (they keep their locked amounts).
 */
class PriceConversionController extends Controller
{
    public function __construct(private PriceConverters $converters) {}

    public function show(): Response
    {
        $this->authorize('core.currencies.rates');

        return Inertia::render('admin/platform/currencies/conversion', [
            'defaultCode' => Currency::default()->code,
            'currencies' => Currency::query()->where('is_default', false)->orderBy('code')->orderBy('id')->get()
                ->map(fn (Currency $currency): array => [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'exchange_rate' => $currency->exchange_rate,
                    'price_rounding' => $currency->price_rounding->value,
                    'price_rounding_label' => $currency->price_rounding->label(),
                    'price_buffer_percent' => $currency->price_buffer_percent,
                ])->values(),
            'previews' => array_map(fn (PriceConverter $converter): array => $converter->preview()->toArray(), $this->converters->all()),
        ]);
    }

    public function apply(string $converter, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('core.currencies.rates');

        $target = $this->converters->get($converter);
        abort_if($target === null, 404);

        $count = $target->apply();
        $activity->log('Converted prices applied', properties: ['converter' => $target->key(), 'rows' => $count], event: 'currency.prices_converted');

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No prices needed changes.|{1} 1 price updated.|[2,*] :count prices updated.', $count)]);

        return to_route('admin.platform.currencies.conversion');
    }
}
