<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallMarkers;
use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\Settings;

/**
 * The company's country, time zone and date format, stored on a first install only. "First install" means no currency
 * exists yet, so this runs before DefaultCurrencies. An installation that already has currencies keeps whatever it
 * uses now, including the built-in defaults it never stored.
 */
final class RegionalSettings implements InstallsReferenceData
{
    public const KEY = 'core_regional';

    public function __construct(private RegionalDefaults $regional, private Settings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Regional settings';
    }

    public function install(): ReferenceDataResult
    {
        if (Currency::query()->exists() || ! $this->regional->writesSettings()) {
            return new ReferenceDataResult(unchanged: 1);
        }

        $this->settings->setMany([
            'core.default_country' => $this->regional->country(),
            'system.timezone' => $this->regional->timezone(),
            'core.date_format' => $this->regional->isNepal() ? RegionalDefaults::DATE_FORMAT : DateFormats::DEFAULT,
            InstallMarkers::REGIONAL_COUNTRY => $this->regional->country(),
        ]);

        return new ReferenceDataResult(created: 3);
    }
}
