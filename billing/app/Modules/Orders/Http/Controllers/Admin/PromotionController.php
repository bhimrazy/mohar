<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Http\Requests\PromotionRequest;
use App\Modules\Orders\Http\Resources\PromotionResource;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Models\PromotionRedemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromotionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('orders.promotions.manage');

        $promotions = Promotion::query()
            ->when($request->filled('search'), fn ($query) => $query->whereLike('code', '%'.$request->string('search')->trim()->toString().'%'))
            ->when($request->input('state') === 'active', fn ($query) => $query->whereNull('expired_at'))
            ->when($request->input('state') === 'expired', fn ($query) => $query->whereNotNull('expired_at'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/promotions/index', [
            'promotions' => PromotionResource::collection($promotions),
            'filters' => $request->only(['search', 'state']),
            'currency' => Currency::default()->code,
        ]);
    }

    public function create(PriceBook $priceBook): Response
    {
        $this->authorize('orders.promotions.manage');

        return Inertia::render('admin/promotions/form', $this->formOptions($priceBook));
    }

    public function store(PromotionRequest $request): RedirectResponse
    {
        $promotion = Promotion::query()->create($request->promotionAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion :code created.', ['code' => $promotion->code])]);

        return to_route('admin.promotions.edit', $promotion);
    }

    public function edit(Promotion $promotion, PriceBook $priceBook): Response
    {
        $this->authorize('orders.promotions.manage');

        return Inertia::render('admin/promotions/form', [
            'promotion' => new PromotionResource($promotion),
            'redemptions' => PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->with(['client:id,first_name,last_name,company_name', 'order:id,number'])
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (PromotionRedemption $redemption): array => [
                    'id' => $redemption->id,
                    'client' => $redemption->client->display_name,
                    'order_id' => $redemption->order_id,
                    'order_number' => $redemption->order->number,
                    'item_type' => $redemption->item_type,
                    'discount_amount' => $redemption->discount_amount,
                    'currency' => $redemption->order->currency ?? Currency::default()->code,
                    'created_at' => $redemption->created_at?->toIso8601String(),
                    'released_at' => $redemption->released_at?->toIso8601String(),
                ]),
            ...$this->formOptions($priceBook),
        ]);
    }

    public function update(PromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        $promotion->update($request->promotionAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion :code updated.', ['code' => $promotion->code])]);

        return to_route('admin.promotions.edit', $promotion);
    }

    public function destroy(Promotion $promotion): RedirectResponse
    {
        $this->authorize('orders.promotions.manage');

        if ($promotion->isRedeemed()) {
            return back()->withErrors(['promotion' => __('This promotion has been used and cannot be deleted. Expire it instead.')]);
        }

        $promotion->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion :code deleted.', ['code' => $promotion->code])]);

        return to_route('admin.promotions.index');
    }

    public function duplicate(Promotion $promotion): RedirectResponse
    {
        $this->authorize('orders.promotions.manage');

        $copy = $promotion->replicate(['uses', 'expired_at']);
        $copy->code = $this->uniqueCopyCode($promotion->code);
        $copy->uses = 0;
        $copy->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion duplicated as :code.', ['code' => $copy->code])]);

        return to_route('admin.promotions.edit', $copy);
    }

    public function expire(Promotion $promotion): RedirectResponse
    {
        $this->authorize('orders.promotions.manage');

        $promotion->update(['expired_at' => now()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion :code expired.', ['code' => $promotion->code])]);

        return back();
    }

    private function uniqueCopyCode(string $code): string
    {
        $base = mb_substr($code, 0, 44).'-COPY';
        $candidate = $base;

        for ($suffix = 2; Promotion::query()->where('code', $candidate)->exists(); $suffix++) {
            $candidate = "{$base}{$suffix}";
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(PriceBook $priceBook): array
    {
        $currency = Currency::default()->code;
        $groups = $priceBook->storefront($currency);
        $products = [];
        $addons = [];

        foreach ($groups as $group) {
            foreach ($group['products'] as $product) {
                $products[] = ['value' => (string) $product['id'], 'label' => "{$group['name']}: {$product['name']}"];

                foreach ($priceBook->addonsFor($product['id'], $currency) as $addon) {
                    $addons[$addon['id']] = ['value' => (string) $addon['id'], 'label' => $addon['name']];
                }
            }
        }

        return [
            'types' => array_map(fn (PromotionType $type): array => ['value' => $type->value, 'label' => $type->label()], PromotionType::cases()),
            'cycles' => array_map(fn (BillingCycle $cycle): array => ['value' => $cycle->value, 'label' => ucfirst($cycle->value)], BillingCycle::cases()),
            'products' => $products,
            'addons' => array_values($addons),
            'currency' => $currency,
        ];
    }
}
