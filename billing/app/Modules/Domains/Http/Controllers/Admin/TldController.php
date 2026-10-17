<?php

namespace App\Modules\Domains\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Actions\SaveTldPrices;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Http\Requests\TldPricesRequest;
use App\Modules\Domains\Http\Requests\TldRequest;
use App\Modules\Domains\Http\Resources\TldResource;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Domains\Support\DomainName;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TldController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('domains.tlds.view');
        $currency = Currency::default()->code;

        $tlds = Tld::query()->withCount('domains')->ordered()->get();
        $prices = TldPrice::query()
            ->whereIn('tld_id', $tlds->pluck('id'))
            ->where('currency', $currency)
            ->whereNull('client_group_id')
            ->where('years', 1)
            ->get()
            ->keyBy('tld_id');

        return Inertia::render('admin/domains/tlds/index', [
            'currency' => $currency,
            'tlds' => $tlds->map(fn (Tld $tld): array => [
                ...(new TldResource($tld))->resolve(),
                'price' => $prices->get($tld->id)?->only(['register', 'transfer', 'renew']),
            ])->values(),
            'canManage' => request()->user()?->hasPermission('domains.tlds.manage') ?? false,
        ]);
    }

    public function create(RegistrarManager $registrars): Response
    {
        $this->authorize('domains.tlds.manage');

        return Inertia::render('admin/domains/tlds/form', [...$this->formOptions($registrars), 'tld' => null, 'prices' => []]);
    }

    public function store(TldRequest $request): RedirectResponse
    {
        $tld = Tld::query()->create([...$request->validated(), 'display_unicode' => DomainName::toUnicode(ltrim($request->string('extension')->toString(), '.'))]);
        $this->activity->log("TLD {$tld->extension} created", $tld);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('TLD :tld created. Add its prices next.', ['tld' => $tld->extension])]);

        return to_route('admin.domains.tlds.edit', $tld);
    }

    public function edit(Tld $tld, RegistrarManager $registrars): Response
    {
        $this->authorize('domains.tlds.view');

        return Inertia::render('admin/domains/tlds/form', [
            ...$this->formOptions($registrars),
            'tld' => new TldResource($tld),
            'prices' => $tld->prices()->orderBy('currency')->orderBy('years')->orderBy('id')
                ->get(['id', 'currency', 'client_group_id', 'years', 'register', 'transfer', 'renew']),
        ]);
    }

    public function update(TldRequest $request, Tld $tld): RedirectResponse
    {
        $tld->update([...$request->validated(), 'display_unicode' => DomainName::toUnicode(ltrim($request->string('extension')->toString(), '.'))]);
        $this->activity->log("TLD {$tld->extension} updated", $tld, null, ['changed' => array_keys($tld->getChanges())]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('TLD :tld updated.', ['tld' => $tld->extension])]);

        return to_route('admin.domains.tlds.edit', $tld);
    }

    public function prices(TldPricesRequest $request, Tld $tld, SaveTldPrices $save): RedirectResponse
    {
        $group = $request->filled('client_group_id') ? $request->integer('client_group_id') : null;
        $save->handle($tld, $request->string('currency')->toString(), $group, $request->rows());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':tld prices saved.', ['tld' => $tld->extension])]);

        return to_route('admin.domains.tlds.edit', $tld);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(RegistrarManager $registrars): array
    {
        return [
            'registrars' => $registrars->keys(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'defaultCurrency' => Currency::default()->code,
            'groups' => ClientGroup::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'badges' => Tld::BADGES,
            'canManage' => request()->user()?->hasPermission('domains.tlds.manage') ?? false,
        ];
    }
}
