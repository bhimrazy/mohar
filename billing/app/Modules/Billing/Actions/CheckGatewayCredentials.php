<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\CertificateInfo;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Events\Billing\GatewayCredentialExpiring;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\DateFormats;
use App\Support\CalendarDate;
use Illuminate\Support\Facades\DB;

/**
 * Daily: warns staff before a certificate an active gateway uses expires (payments-gateways-expansion §9.2). Each
 * stage of billing.gateways.credential_warning_days (30, 7 and 1 days before by default), and the expiry itself,
 * raises GatewayCredentialExpiring once per certificate; the stage reached is recorded beside the certificate's
 * metadata, so a missed day still warns once, and a new upload starts again. The work is one row per registered driver
 * that declares a certificate setting, each read by its unique key under a row lock.
 */
class CheckGatewayCredentials
{
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
        $raised = 0;

        foreach ($this->gateways->drivers() as $key => $driver) {
            $fields = array_filter(array_map(SettingField::fromArray(...), $driver->settingsFields()), fn (SettingField $field): bool => $field->type === FieldType::Certificate);

            if ($fields !== []) {
                $raised += DB::transaction(fn (): int => $this->checkGateway((string) $key, $fields, $today, $stages));
            }
        }

        return $raised;
    }

    /**
     * The stage a certificate with $daysLeft has reached: the smallest warning day it is within, 0 once it has expired,
     * null before the first.
     *
     * @param  list<int>  $stages  descending
     */
    public static function stage(int $daysLeft, array $stages): ?int
    {
        if ($daysLeft <= 0) {
            return 0;
        }

        $reached = array_filter($stages, fn (int $stage): bool => $daysLeft <= $stage);

        return $reached === [] ? null : min($reached);
    }

    /**
     * The dashboard health line: the active gateway certificate closest to expiry, amber within the first warning stage
     * and red once expired; null when no active gateway has one.
     *
     * @return array{label: string, detail: string, value: string, tone: string, icon: string, href: string}|null
     */
    public function health(CalendarDate $today): ?array
    {
        $soonest = null;

        foreach ($this->gateways->drivers() as $key => $driver) {
            foreach ($driver->settingsFields() as $field) {
                if (($field['type'] ?? null) !== FieldType::Certificate->value) {
                    continue;
                }

                $gateway = Gateway::query()->where('key', $key)->where('is_active', true)->first();
                $info = $gateway !== null ? $this->gateways->config($gateway)->certificate($field['key']) : null;

                if ($gateway !== null && $info !== null && ($soonest === null || $info->notAfter->lt($soonest[1]->notAfter))) {
                    $soonest = [$gateway, $info];
                }
            }
        }

        if ($soonest === null) {
            return null;
        }

        [$gateway, $info] = $soonest;
        $daysLeft = $this->daysLeft($info, $today);
        $date = CalendarDate::parse($this->clock->localDate($info->notAfter))->format(DateFormats::current());

        return [
            'label' => __('Gateway certificates'),
            'detail' => $daysLeft <= 0
                ? __(':gateway certificate expired on :date', ['gateway' => $gateway->display_name, 'date' => $date])
                : __(':gateway certificate expires on :date', ['gateway' => $gateway->display_name, 'date' => $date]),
            'value' => $daysLeft <= 0 ? __('Expired') : trans_choice('{1} 1 day left|[2,*] :count days left', $daysLeft),
            'tone' => $daysLeft <= 0 ? 'danger' : ($daysLeft <= max([0, ...$this->stages()]) ? 'warning' : 'success'),
            'icon' => 'ShieldCheck',
            'href' => route('admin.billing.gateways.edit', $gateway->key),
        ];
    }

    /**
     * Days left until expiry on the company calendar, from $today.
     */
    public function daysLeft(CertificateInfo $info, CalendarDate $today): int
    {
        return $today->daysUntil(CalendarDate::parse($this->clock->localDate($info->notAfter)));
    }

    /**
     * @param  array<int, SettingField>  $fields
     * @param  list<int>  $stages
     */
    private function checkGateway(string $key, array $fields, CalendarDate $today, array $stages): int
    {
        $gateway = Gateway::query()->where('key', $key)->where('is_active', true)->lockForUpdate()->first();
        $config = $gateway?->config ?? [];
        $events = [];

        foreach ($fields as $field) {
            $stored = $config[GatewayConfig::CERTIFICATES][$field->key] ?? null;
            $info = is_array($stored) && (string) ($config[$field->key] ?? '') !== '' ? CertificateInfo::fromArray($stored) : null;

            if ($gateway === null || $info === null) {
                continue;
            }

            $daysLeft = $this->daysLeft($info, $today);
            $stage = self::stage($daysLeft, $stages);
            $warned = $stored['warned_stage'] ?? null;

            if ($stage === null || (is_int($warned) && $warned <= $stage)) {
                continue;
            }

            $config[GatewayConfig::CERTIFICATES][$field->key]['warned_stage'] = $stage;
            $events[] = new GatewayCredentialExpiring($key, $gateway->display_name, $field->key, $field->label, $info->toArray()['not_after'], $daysLeft);
        }

        if ($gateway !== null && $events !== []) {
            $gateway->update(['config' => $config]);
            AfterCommit::dispatch(...$events);
        }

        return count($events);
    }

    /**
     * @return list<int> warning days, descending: at most five, each 1 to 365
     */
    private function stages(): array
    {
        $days = array_values(array_unique(array_filter($this->settings->intList('gateways.credential_warning_days'), fn (int $day): bool => $day >= 1 && $day <= 365)));
        rsort($days);

        return array_slice($days, 0, 5);
    }
}
