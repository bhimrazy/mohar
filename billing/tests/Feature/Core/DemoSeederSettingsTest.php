<?php

use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Support\Settings;

it('seeds the fictional demo host into empty company settings only', function () {
    $settings = app(Settings::class);
    $settings->setMany(['core.company_name' => 'Acme Cloud', 'core.support_email' => '']);

    $this->seed(CoreSeeder::class);
    $settings->set('core.whatsapp', '+977 9811111111');
    $this->seed(CoreSeeder::class);

    expect($settings->get('core.company_name'))->toBe('Acme Cloud')
        ->and($settings->get('core.support_email'))->toBe('support@example.com')
        ->and($settings->get('core.whatsapp'))->toBe('+977 9811111111')
        ->and($settings->get('core.social_facebook'))->toStartWith('https://example.com/');
});
