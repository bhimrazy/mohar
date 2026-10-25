<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Core\CompanyClock;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Events\Billing\GatewayCredentialExpired;
use App\Events\Billing\GatewayCredentialExpiring;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\IntegrationCredential;
use App\Modules\Core\Support\DateFormats;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily: warns staff before a certificate an active gateway uses expires (payments-gateways-expansion §9.2). Each
 * stage of billing.gateways.certificate_warning_days (30, 7 and 1 days before by default) raises
 * GatewayCredentialExpiring once, and the expiry itself GatewayCredentialExpired once. The stage reached is kept in
 * integration_credentials (never in the encrypted settings), so a missed day still warns once and a new upload starts
 * again. Each run reads at most MAX_PER_RUN rows on the (family, not_after) index, soonest expiry first.
 */
class CheckGatewayCredentials
{
    public const MAX_PER_RUN = 500;

    public function __construct(
        private GatewayManager $gateways,
        private BillingSettings $settings,
        private CompanyClock $clock,
    ) {}

    /**
     * @return int warnings raised
     */
    public function handle(CalendarDate $today): int
    {
        $stages = $this->stages();
        $labels = $this->certificateLabels();
        $raised = 0;

        foreach ($this->due($stages, array_keys($labels)) as $credential) {
            $label = $labels[$credential->owner_key][$credential->setting_key] ?? null;

            if ($label !== null) {
                $raised += DB::transaction(fn (): int => $this->warn($credential->id, $label, $today, $stages));
            }
        }

        return $raised;
    }

    /**
     * The stage a certificate has reached: 0 once it has expired, else the smallest warning day it is within (the
     * expiry date itself counts as within every stage), null before the first.
     *
     * @param  list<int>  $stages  descending
     */
    public static function stage(int $daysLeft, bool $expired, array $stages): ?int
    {
        if ($expired) {
            return 0;
        }

        $reached = array_filter($stages, fn (int $stage): bool => $daysLeft <= $stage);

        return $reached === [] ? null : min($reached);
    }

    /**
     * The dashboard health line: the active gateway certificate closest to expiry, amber within the first warning stage
     * and red once expired; null when no active gateway has one. Reads no encrypted settings.
     *
     * @return array{label: string, detail: string, value: string, tone: string, icon: string, href: string}|null
     */
    public function health(CalendarDate $today): ?array
    {
        $labels = $this->certificateLabels();
        $names = $this->activeGatewayNames(array_keys($labels));
        // Only the certificate settings active gateways' drivers still declare.
        $soonest = $names === [] ? null : IntegrationCredential::query()->where('family', IntegrationCredential::GATEWAY)
            ->where(function ($query) use ($names, $labels): void {
                foreach (array_keys($names) as $key) {
                    $query->orWhere(fn ($pair) => $pair->where('owner_key', $key)->whereIn('setting_key', array_keys($labels[$key])));
                }
            })
            ->orderBy('not_after')->orderBy('id')->first();

        if ($soonest === null) {
            return null;
        }

        $expired = ! $soonest->not_after->isFuture();
        $daysLeft = $this->daysLeft($soonest->not_after, $today);
        $name = $names[$soonest->owner_key];
        $date = CalendarDate::parse($this->clock->localDate($soonest->not_after))->format(DateFormats::current());

        return [
            'label' => __('Gateway certificates'),
            'detail' => $expired
                ? __(':gateway certificate expired on :date', ['gateway' => $name, 'date' => $date])
                : __(':gateway certificate expires on :date', ['gateway' => $name, 'date' => $date]),
            'value' => match (true) {
                $expired => __('Expired'),
                $daysLeft <= 0 => __('Expires today'),
                default => trans_choice('{1} 1 day left|[2,*] :count days left', $daysLeft),
            },
            'tone' => $expired ? 'danger' : ($daysLeft <= max([0, ...$this->stages()]) ? 'warning' : 'success'),
            'icon' => 'ShieldCheck',
            'href' => route('admin.billing.gateways.edit', $soonest->owner_key),
        ];
    }

    /**
     * Company-calendar days from $today to the expiry instant's date.
     */
    public function daysLeft(CarbonImmutable $notAfter, CalendarDate $today): int
    {
        return $today->daysUntil(CalendarDate::parse($this->clock->localDate($notAfter)));
    }

    /**
     * Credentials of active gateways that may owe a warning: within the first stage, not yet warned for expiry.
     *
     * @param  list<int>  $stages
     * @param  list<string>  $gatewayKeys
     * @return Collection<int, IntegrationCredential>
     */
    private function due(array $stages, array $gatewayKeys): Collection
    {
        $active = array_keys($this->activeGatewayNames($gatewayKeys));

        if ($active === []) {
            return new Collection;
        }

        return IntegrationCredential::query()
            ->where('family', IntegrationCredential::GATEWAY)
            ->where('not_after', '<=', now()->addDays(max([0, ...$stages]) + 1))
            ->where(fn ($query) => $query->whereNull('warned_stage')->orWhere('warned_stage', '>', 0))
            ->whereIn('owner_key', $active)
            ->orderBy('not_after')->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get();
    }

    /**
     * @param  list<int>  $stages
     */
    private function warn(int $id, string $label, CalendarDate $today, array $stages): int
    {
        $credential = IntegrationCredential::query()->lockForUpdate()->find($id);

        if ($credential === null) {
            return 0;
        }

        $expired = ! $credential->not_after->isFuture();
        $daysLeft = $this->daysLeft($credential->not_after, $today);
        $stage = self::stage($daysLeft, $expired, $stages);

        if ($stage === null || ($credential->warned_stage !== null && $credential->warned_stage <= $stage)) {
            return 0;
        }

        $credential->update(['warned_stage' => $stage]);
        $name = (string) Gateway::query()->where('key', $credential->owner_key)->value('display_name');
        $notAfter = $credential->not_after->utc();

        AfterCommit::dispatch($expired
            ? new GatewayCredentialExpired($credential->owner_key, $name, $credential->setting_key, $label, $notAfter)
            : new GatewayCredentialExpiring($credential->owner_key, $name, $credential->setting_key, $label, $notAfter, $daysLeft));

        return 1;
    }

    /**
     * Labels of the certificate settings each registered driver still declares.
     *
     * @return array<string, array<string, string>> gateway key => setting key => label
     */
    private function certificateLabels(): array
    {
        $labels = [];

        foreach ($this->gateways->drivers() as $key => $driver) {
            // A driver whose fields cannot be read (an invalid key, a malformed row) is reported and skipped, never the
            // others.
            try {
                foreach ($this->certificateFields($driver) as $field) {
                    $labels[(string) $key][$field->key] = $field->label;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $labels;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> key => display name, active gateways only
     */
    private function activeGatewayNames(array $keys): array
    {
        return $keys === [] ? [] : Gateway::query()->whereIn('key', $keys)->where('is_active', true)->pluck('display_name', 'key')->all();
    }

    /**
     * @return list<SettingField>
     */
    private function certificateFields(GatewayDriver $driver): array
    {
        return array_values(array_filter(array_map(SettingField::fromArray(...), $driver->settingsFields()), fn (SettingField $field): bool => $field->type === FieldType::Certificate));
    }

    /**
     * @return list<int> warning days, descending: at most five, each 1 to 365
     */
    private function stages(): array
    {
        $days = array_values(array_unique(array_filter($this->settings->intList('gateways.certificate_warning_days'), fn (int $day): bool => $day >= 1 && $day <= 365)));
        rsort($days);

        return array_slice($days, 0, 5);
    }
}
